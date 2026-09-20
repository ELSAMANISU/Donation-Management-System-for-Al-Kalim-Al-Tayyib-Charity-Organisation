<?php

namespace Tests\Feature\AidDelivery;

use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AidDeliverySchemaTest extends TestCase
{
    public static function drivers(): array
    {
        return [[MySqlConnection::class, 'mysql', '8.0.36'], [MariaDbConnection::class, 'mariadb', '10.6.23'], [MariaDbConnection::class, 'mariadb', '10.11.8']];
    }

    #[DataProvider('drivers')]
    public function test_offline_operational_schema_has_exact_money_generated_unique_slot_restrict_and_datetime(string $class, string $driver, string $version): void
    {
        $connection = Mockery::mock($class.'[getServerVersion]', [fn () => throw new RuntimeException('No database connection permitted.'), 'schema_only', '', ['driver' => $driver]]);
        $connection->shouldReceive('getServerVersion')->andReturn($version);
        $connection->useDefaultSchemaGrammar();
        $originalSchema = Schema::getFacadeRoot();
        $originalDB = DB::getFacadeRoot();
        $sql = [];
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('getDriverName')->andReturn($driver);
        DB::shouldReceive('statement')->once()->andReturnUsing(function ($statement) use (&$sql) {
            $sql['check'] = $statement;

            return true;
        });
        Schema::shouldReceive('create')->times(3)->andReturnUsing(function ($table, $callback) use ($connection, &$sql) {
            $blueprint = new Blueprint($connection, $table);
            $blueprint->create();
            $callback($blueprint);
            $sql[$table] = implode("\n", $blueprint->toSql());
        });
        Schema::shouldReceive('table')->once()->andReturnUsing(function ($table, $callback) use ($connection, &$sql) {
            $blueprint = new Blueprint($connection, $table, $callback);
            $sql[$table] = implode("\n", $blueprint->toSql());
        });
        try {
            (require database_path('migrations/2026_09_17_000000_create_aid_delivery_ledger.php'))->up();
        } finally {
            Schema::swap($originalSchema);
            DB::swap($originalDB);
        }
        $this->assertStringContainsString('`amount` decimal(18, 2) not null', $sql['aid_deliveries']);
        $this->assertStringContainsString("CASE WHEN state IN ('in_progress', 'problem') THEN coordination_id ELSE NULL END", $sql['aid_deliveries']);
        $this->assertStringContainsString('stored', $sql['aid_deliveries']);
        $this->assertStringContainsString('unique `aid_delivery_unfinished_unique`', $sql['aid_deliveries']);
        $this->assertStringContainsString("CHECK (amount > 0 AND currency = 'SDG')", $sql['check']);
        foreach (['aid_deliveries' => ['started_at', 'completed_at'], 'aid_delivery_transitions' => ['created_at'], 'aid_delivery_proofs' => ['created_at']] as $table => $fields) {
            foreach ($fields as $field) {
                $this->assertStringContainsString('`'.$field.'` datetime', $sql[$table]);
            }
            foreach (['timestamp', 'on update', 'default'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtolower($sql[$table]));
            }
            $this->assertStringContainsString('on delete restrict', $sql[$table]);
        }
        $this->assertStringContainsString('`delivery_id`, `revision`', $sql['aid_delivery_transitions']);
        $this->assertStringContainsString('unique `aid_delivery_proofs_delivery_id_unique`', $sql['aid_delivery_proofs']);
    }
}
