<?php

namespace Tests\Feature\HelpApplication;

use App\Enums\HelpApplicationDuplicateWarningStatus;
use App\Enums\IdentityDocumentType;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDuplicateWarning;
use App\Services\IdentityBlindIndex;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class HelpApplicationDuplicateWarningDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_enum_and_additive_identity_index_are_exact(): void
    {
        $this->assertSame(['unreviewed', 'confirmed_match', 'dismissed'], array_column(HelpApplicationDuplicateWarningStatus::cases(), 'value'));
        $this->assertTrue(Schema::hasColumns('help_application_duplicate_warnings', [
            'id', 'reference', 'submitted_application_id', 'matched_application_id', 'status',
            'resolved_by', 'resolved_at', 'resolution_note', 'created_at', 'updated_at',
        ]));
        $indexes = collect(DB::select("PRAGMA index_list('help_application_duplicate_warnings')"))->pluck('name');
        $this->assertContains('help_application_duplicate_warnings_pair_unique', $indexes);
        $this->assertContains('help_application_duplicate_warnings_status_index', $indexes);
        $applicationIndexes = collect(DB::select("PRAGMA index_list('help_applications')"))->pluck('name');
        $this->assertContains('help_applications_identity_version_lookup_index', $applicationIndexes);
        $this->assertContains('help_applications_identity_blind_index_index', $applicationIndexes);
    }

    public function test_factory_model_relationships_scopes_and_encryption_are_private(): void
    {
        $warning = HelpApplicationDuplicateWarning::factory()->create(['resolution_note' => 'private resolution']);
        $this->assertNotSame($warning->resolution_note, DB::table('help_application_duplicate_warnings')->where('id', $warning->id)->value('resolution_note'));
        $this->assertSame('reference', $warning->getRouteKeyName());
        $this->assertFalse($warning->submittedApplication->is($warning->matchedApplication));
        $this->assertSame(1, $warning->submittedApplication->duplicateWarningsRaised()->unreviewed()->count());
        $this->assertSame(1, $warning->matchedApplication->duplicateWarningsMatched()->count());
        $serialized = $warning->toArray();
        foreach (['submitted_application_id', 'matched_application_id', 'resolved_by', 'resolution_note'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $serialized);
        }
        $this->assertFalse(method_exists($warning, 'campaign'));
    }

    public function test_self_pair_is_rejected_and_directional_pair_is_unique(): void
    {
        $application = HelpApplication::factory()->pending()->create();
        try {
            HelpApplicationDuplicateWarning::factory()->create([
                'submitted_application_id' => $application->id,
                'matched_application_id' => $application->id,
            ]);
            $this->fail('Self pair was accepted.');
        } catch (LogicException) {
            $this->assertDatabaseCount('help_application_duplicate_warnings', 0);
        }

        $warning = HelpApplicationDuplicateWarning::factory()->create();
        $this->expectException(QueryException::class);
        HelpApplicationDuplicateWarning::factory()->create([
            'submitted_application_id' => $warning->submitted_application_id,
            'matched_application_id' => $warning->matched_application_id,
        ]);
    }

    public function test_production_migration_defines_the_exact_mysql_mariadb_self_pair_check(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_01_010000_create_help_application_duplicate_warnings_table.php'));
        $this->assertIsString($migration);
        $this->assertStringContainsString("['mysql', 'mariadb']", $migration);
        $this->assertStringContainsString(
            'ADD CONSTRAINT help_application_duplicate_warnings_not_self CHECK (submitted_application_id <> matched_application_id)',
            $migration,
        );
        $this->assertStringContainsString('help_application_duplicate_warnings_pair_unique', $migration);
    }

    public function test_production_migration_uses_exact_mysql_safe_constraint_and_index_names(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_01_010000_create_help_application_duplicate_warnings_table.php'));
        $this->assertIsString($migration);

        $foreignKeys = [
            'submitted_application_id' => ['help_app_duplicate_warnings_submitted_fk', 'help_applications', 'restrictOnDelete'],
            'matched_application_id' => ['help_app_duplicate_warnings_matched_fk', 'help_applications', 'restrictOnDelete'],
            'resolved_by' => ['help_app_duplicate_warnings_resolver_fk', 'users', 'nullOnDelete'],
        ];

        foreach ($foreignKeys as $column => [$name, $table, $deleteAction]) {
            $this->assertStringContainsString(
                "\$table->foreign('$column', '$name')->references('id')->on('$table')->$deleteAction();",
                $migration,
            );
        }

        $explicitIdentifiers = [
            ...array_column($foreignKeys, 0),
            'help_application_duplicate_warnings_pair_unique',
            'help_application_duplicate_warnings_status_index',
            'help_application_duplicate_warnings_matched_index',
            'help_application_duplicate_warnings_not_self',
        ];

        foreach ($explicitIdentifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
            $this->assertStringContainsString($identifier, $migration);
        }
    }

    public function test_multiple_prior_matches_and_resolver_deletion_preserve_history(): void
    {
        $submitted = HelpApplication::factory()->pending()->create();
        $first = HelpApplication::factory()->closed()->create();
        $second = HelpApplication::factory()->closed()->create();
        HelpApplicationDuplicateWarning::factory()->create(['submitted_application_id' => $submitted->id, 'matched_application_id' => $first->id]);
        $resolved = HelpApplicationDuplicateWarning::factory()->confirmed()->create(['submitted_application_id' => $submitted->id, 'matched_application_id' => $second->id]);
        $resolver = $resolved->resolver;
        $resolver->delete();

        $this->assertSame(2, HelpApplicationDuplicateWarning::query()->forSubmittedApplication($submitted)->inAdministratorOrder()->count());
        $this->assertNull($resolved->refresh()->resolved_by);
        $this->assertDatabaseCount('help_application_duplicate_warnings', 2);
    }

    public function test_application_deletion_is_restricted(): void
    {
        $warning = HelpApplicationDuplicateWarning::factory()->create();
        $this->expectException(QueryException::class);
        $warning->submittedApplication->delete();
    }

    public function test_configured_key_versions_are_sorted_complete_and_support_rotation(): void
    {
        $originalVersion = config('identity.blind_index.current_version');
        $originalKeys = config('identity.blind_index.keys');
        $keys = [2 => base64_encode(str_repeat('b', 32)), 1 => base64_encode(str_repeat('a', 32))];

        try {
            config()->set('identity.blind_index.current_version', '2');
            config()->set('identity.blind_index.keys', $keys);
            $service = app(IdentityBlindIndex::class);
            $this->assertSame(2, $service->currentKeyVersion());
            $this->assertSame([1, 2], $service->configuredKeyVersions());
            $this->assertNotSame(
                $service->compute(IdentityDocumentType::Passport, 'sd', 'ABC', 1),
                $service->compute(IdentityDocumentType::Passport, 'sd', 'ABC', 2),
            );
        } finally {
            config()->set('identity.blind_index.current_version', $originalVersion);
            config()->set('identity.blind_index.keys', $originalKeys);
        }
    }

    public function test_malformed_missing_or_retired_key_configuration_fails_closed(): void
    {
        $service = app(IdentityBlindIndex::class);
        $originalVersion = config('identity.blind_index.current_version');
        $originalKeys = config('identity.blind_index.keys');
        $cases = [
            [1, []],
            [2, [1 => base64_encode(str_repeat('a', 32))]],
            [1, [0 => base64_encode(str_repeat('a', 32))]],
            [1, [-1 => base64_encode(str_repeat('a', 32))]],
            [1, ['01' => base64_encode(str_repeat('a', 32))]],
            [1, [65536 => base64_encode(str_repeat('a', 32))]],
            [1, [1 => 'not-base64']],
            [1, [1 => base64_encode('short')]],
            [1, [1 => ['not-a-secret']]],
            ['01', [1 => base64_encode(str_repeat('a', 32))]],
            [0, [1 => base64_encode(str_repeat('a', 32))]],
            [-1, [1 => base64_encode(str_repeat('a', 32))]],
            [1.5, [1 => base64_encode(str_repeat('a', 32))]],
            [65536, [65535 => base64_encode(str_repeat('a', 32))]],
        ];

        try {
            foreach ($cases as [$current, $keys]) {
                config()->set('identity.blind_index.current_version', $current);
                config()->set('identity.blind_index.keys', $keys);
                try {
                    $service->configuredKeyVersions();
                    $this->fail('Unsafe key configuration was accepted.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Identity blind-index configuration is invalid.', $exception->getMessage());
                }
            }
        } finally {
            config()->set('identity.blind_index.current_version', $originalVersion);
            config()->set('identity.blind_index.keys', $originalKeys);
        }
    }

    public function test_unavailable_stored_version_can_be_detected_without_decrypting_unrelated_identity(): void
    {
        $originalVersion = config('identity.blind_index.current_version');
        $originalKeys = config('identity.blind_index.keys');

        try {
            config()->set('identity.blind_index.current_version', 1);
            config()->set('identity.blind_index.keys', [1 => base64_encode(str_repeat('a', 32))]);
            HelpApplication::factory()->create([
                'identity_document_number' => 'must-not-be-decrypted-for-version-check',
                'identity_blind_index' => str_repeat('a', 64),
                'identity_blind_index_version' => 2,
            ]);
            $available = app(IdentityBlindIndex::class)->configuredKeyVersions();
            $unavailableExists = HelpApplication::query()->whereNotNull('identity_blind_index_version')->whereNotIn('identity_blind_index_version', $available)->exists();
            $this->assertTrue($unavailableExists);
        } finally {
            config()->set('identity.blind_index.current_version', $originalVersion);
            config()->set('identity.blind_index.keys', $originalKeys);
        }
    }
}
