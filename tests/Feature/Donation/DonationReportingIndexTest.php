<?php

namespace Tests\Feature\Donation;

use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DonationReportingIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_reporting_index_and_reversible_migration(): void
    {
        $find = fn () => collect(Schema::getIndexes('donations'))->firstWhere('name', 'donations_reporting_index');
        $this->assertSame(['status', 'paid_at', 'id'], $find()['columns']);
        $migration = require database_path('migrations/2026_09_22_000000_add_donation_reporting_index.php');
        $migration->down();
        $this->assertNull($find());
        $this->assertTrue(Schema::hasTable('donations'));
        $migration->up();
        $this->assertSame(['status', 'paid_at', 'id'], $find()['columns']);
    }

    public static function drivers(): array
    {
        return [[MySqlConnection::class, 'mysql', '8.0.36'], [MariaDbConnection::class, 'mariadb', '10.6.23'], [MariaDbConnection::class, 'mariadb', '10.11.8']];
    }

    #[DataProvider('drivers')]
    public function test_offline_operational_index_compilation(string $class, string $driver, string $version): void
    {
        $connection = Mockery::mock($class.'[getServerVersion]', [fn () => throw new \RuntimeException('No operational connection permitted.'), 'offline', '', ['driver' => $driver]]);
        $connection->shouldReceive('getServerVersion')->andReturn($version);
        $connection->useDefaultSchemaGrammar();
        $original = Schema::getFacadeRoot();
        $sql = [];
        Schema::shouldReceive('table')->twice()->andReturnUsing(function ($table, $callback) use ($connection, &$sql): void {
            $sql[] = implode("\n", (new Blueprint($connection, $table, $callback))->toSql());
        });
        try {
            $migration = require database_path('migrations/2026_09_22_000000_add_donation_reporting_index.php');
            $migration->up();
            $migration->down();
        } finally {
            Schema::swap($original);
        }
        $this->assertStringContainsString('`donations_reporting_index`(`status`, `paid_at`, `id`)', $sql[0]);
        $this->assertStringContainsString('drop index `donations_reporting_index`', $sql[1]);
    }
}
