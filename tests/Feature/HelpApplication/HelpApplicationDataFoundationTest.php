<?php

namespace Tests\Feature\HelpApplication;

use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\PublicIdentityPreference;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use App\Services\IdentityBlindIndex;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class HelpApplicationDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_the_private_foundation_without_campaign_coupling(): void
    {
        $this->assertTrue(Schema::hasColumns('help_applications', [
            'id', 'reference', 'applicant_id', 'category_id', 'status', 'open_slot',
            'full_name', 'email', 'phone', 'address', 'date_of_birth',
            'identity_document_type', 'identity_issuing_country', 'identity_document_number',
            'identity_blind_index', 'identity_blind_index_version', 'requested_amount',
            'private_story', 'preferred_receiving_method', 'public_identity_preference',
            'consent_version', 'consented_at', 'category_assigned_by', 'category_assigned_at',
            'reviewed_by', 'review_started_at', 'decided_by', 'decided_at', 'submitted_at',
            'status_changed_at', 'appeal_eligibility_ended_at', 'updated_by', 'created_at', 'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('help_applications', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('campaigns', 'help_application_id'));
        $this->assertFalse(method_exists(Campaign::class, 'helpApplication'));
        $this->assertFalse(method_exists(HelpApplication::class, 'campaign'));
        $this->assertFalse(collect(Route::getRoutes())->contains(function ($route): bool {
            $uri = strtolower($route->uri());
            $name = strtolower((string) $route->getName());
            $action = strtolower($route->getActionName());

            return collect([
                'help-application', 'help_applications', 'help/applications', 'help-applications',
            ])->contains(fn (string $variant): bool => str_contains($uri, $variant))
                || collect([
                    'help.application', 'help_application', 'help-application', 'help-applications',
                ])->contains(fn (string $variant): bool => str_contains($name, $variant))
                || str_contains($action, 'helpapplication')
                || str_contains($action, 'help_application');
        }));
    }

    public function test_sqlite_schema_has_expected_named_indexes_and_foreign_keys(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('help_applications')"))->pluck('name');

        $this->assertTrue($indexes->contains('help_applications_reference_unique'));
        $this->assertTrue($indexes->contains('help_applications_applicant_open_unique'));
        $this->assertTrue($indexes->contains('help_applications_applicant_status_index'));
        $this->assertTrue($indexes->contains('help_applications_category_status_index'));
        $this->assertTrue($indexes->contains('help_applications_review_order_index'));
        $this->assertTrue($indexes->contains('help_applications_identity_blind_index_index'));
        $this->assertTrue($indexes->contains('help_applications_category_assigned_by_index'));
        $this->assertTrue($indexes->contains('help_applications_reviewed_by_index'));
        $this->assertTrue($indexes->contains('help_applications_decided_by_index'));
        $this->assertTrue($indexes->contains('help_applications_updated_by_index'));

        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('help_applications')"));
        $this->assertSame(
            ['applicant_id', 'category_assigned_by', 'category_id', 'decided_by', 'reviewed_by', 'updated_by'],
            $foreignKeys->pluck('from')->sort()->values()->all(),
        );
        $this->assertSame('RESTRICT', $foreignKeys->firstWhere('from', 'applicant_id')->on_delete);
        $this->assertSame('RESTRICT', $foreignKeys->firstWhere('from', 'category_id')->on_delete);

        foreach (['category_assigned_by', 'reviewed_by', 'decided_by', 'updated_by'] as $actorColumn) {
            $this->assertSame('SET NULL', $foreignKeys->firstWhere('from', $actorColumn)->on_delete);
        }
    }

    public function test_enums_contain_exactly_the_approved_values(): void
    {
        $this->assertSame([
            'draft', 'pending', 'under_review', 'additional_information_required',
            'approved', 'rejected', 'appealed', 'converted_to_campaign',
            'campaign_active', 'aid_delivery', 'completed', 'closed',
        ], array_column(HelpApplicationStatus::cases(), 'value'));
        $this->assertSame(['national_id', 'passport'], array_column(IdentityDocumentType::cases(), 'value'));
        $this->assertSame(['full_name', 'first_name', 'anonymous'], array_column(PublicIdentityPreference::cases(), 'value'));
    }

    public function test_statuses_have_exact_open_and_terminal_classification(): void
    {
        $open = array_values(array_map(
            fn (HelpApplicationStatus $status): string => $status->value,
            array_filter(HelpApplicationStatus::cases(), fn (HelpApplicationStatus $status): bool => $status->isOpen()),
        ));
        $terminal = array_values(array_map(
            fn (HelpApplicationStatus $status): string => $status->value,
            array_filter(HelpApplicationStatus::cases(), fn (HelpApplicationStatus $status): bool => $status->isTerminal()),
        ));

        $this->assertSame([
            'draft', 'pending', 'under_review', 'additional_information_required',
            'approved', 'rejected', 'appealed', 'converted_to_campaign',
            'campaign_active', 'aid_delivery',
        ], $open);
        $this->assertSame(['completed', 'closed'], $terminal);
    }

    public function test_default_factory_creates_a_private_draft_for_an_active_ordinary_user(): void
    {
        $application = HelpApplication::factory()->create();
        $secondApplication = HelpApplication::factory()->create();

        $this->assertSame(HelpApplicationStatus::Draft, $application->status);
        $this->assertTrue($application->open_slot);
        $this->assertTrue(Str::isUuid($application->reference));
        $this->assertTrue(Str::isUuid($secondApplication->reference));
        $this->assertNotSame($application->reference, $secondApplication->reference);
        $this->assertTrue($application->applicant->is_active);
        $this->assertSame('user', $application->applicant->role->value);
        $this->assertNull($application->category_id);
        $this->assertNull($application->category_assigned_by);
        $this->assertNull($application->category_assigned_at);
        $this->assertNull($application->reviewed_by);
        $this->assertNull($application->decided_by);
        $this->assertNull($application->updated_by);
        $this->assertNull($application->submitted_at);
        $this->assertNull($application->review_started_at);
        $this->assertNull($application->decided_at);
        $this->assertNull($application->status_changed_at);
        $this->assertNull($application->appeal_eligibility_ended_at);
        $this->assertNull($application->consent_version);
        $this->assertNull($application->consented_at);
        $this->assertSame('help_application_v1', HelpApplication::CONSENT_VERSION);
    }

    public function test_database_rejects_a_duplicate_uuid_reference(): void
    {
        $application = HelpApplication::factory()->create();

        $this->expectException(QueryException::class);
        HelpApplication::factory()->create(['reference' => $application->reference]);
    }

    public function test_every_factory_status_keeps_open_slot_consistent(): void
    {
        $states = [
            'draft' => fn () => HelpApplication::factory(),
            'pending' => fn () => HelpApplication::factory()->pending(),
            'under_review' => fn () => HelpApplication::factory()->underReview(),
            'additional_information_required' => fn () => HelpApplication::factory()->additionalInformationRequired(),
            'approved' => fn () => HelpApplication::factory()->approved(),
            'rejected' => fn () => HelpApplication::factory()->rejected(),
            'appealed' => fn () => HelpApplication::factory()->appealed(),
            'converted_to_campaign' => fn () => HelpApplication::factory()->convertedToCampaign(),
            'campaign_active' => fn () => HelpApplication::factory()->campaignActive(),
            'aid_delivery' => fn () => HelpApplication::factory()->aidDelivery(),
            'completed' => fn () => HelpApplication::factory()->completed(),
            'closed' => fn () => HelpApplication::factory()->closed(),
        ];

        foreach ($states as $value => $factory) {
            $application = $factory()->create();
            $status = HelpApplicationStatus::from($value);

            $this->assertSame($status, $application->status);
            $this->assertSame($status->isOpen() ? true : null, $application->open_slot);
        }

        $this->assertSame(
            ['completed', 'closed'],
            HelpApplication::query()->whereNull('open_slot')->orderBy('id')->get()
                ->map(fn (HelpApplication $application): string => $application->status->value)->all(),
        );
        $this->assertSame(
            ['draft', 'pending', 'under_review', 'additional_information_required', 'approved',
                'rejected', 'appealed', 'converted_to_campaign', 'campaign_active', 'aid_delivery'],
            HelpApplication::query()->where('open_slot', true)->orderBy('id')->get()
                ->map(fn (HelpApplication $application): string => $application->status->value)->all(),
        );
    }

    public function test_database_rejects_a_second_open_application_for_the_same_applicant(): void
    {
        $applicant = User::factory()->create();
        HelpApplication::factory()->for($applicant, 'applicant')->create();

        $this->expectException(QueryException::class);
        HelpApplication::factory()->for($applicant, 'applicant')->rejected()->create();
    }

    public function test_terminal_history_and_closure_allow_a_new_open_application(): void
    {
        $applicant = User::factory()->create();
        HelpApplication::factory()->for($applicant, 'applicant')->completed()->create();
        HelpApplication::factory()->for($applicant, 'applicant')->closed()->create();
        $rejected = HelpApplication::factory()->for($applicant, 'applicant')->rejected()->create();

        $this->assertTrue($rejected->status->isOpen());
        $this->assertTrue($rejected->open_slot);

        $rejected->status = HelpApplicationStatus::Closed;
        $rejected->open_slot = null;
        $rejected->appeal_eligibility_ended_at = now();
        $rejected->save();

        $draft = HelpApplication::factory()->for($applicant, 'applicant')->create();
        $this->assertSame(4, $applicant->helpApplications()->count());
        $this->assertSame(HelpApplicationStatus::Draft, $draft->status);
    }

    public function test_appealed_application_occupies_the_open_slot(): void
    {
        $applicant = User::factory()->create();
        $appealed = HelpApplication::factory()->for($applicant, 'applicant')->appealed()->create();

        $this->assertTrue($appealed->open_slot);
        $this->expectException(QueryException::class);
        HelpApplication::factory()->for($applicant, 'applicant')->create();
    }

    public function test_foreign_key_deletion_behavior_and_historical_category_relationship(): void
    {
        $applicant = User::factory()->create();
        $administrator = User::factory()->admin()->create();
        $category = Category::factory()->create();
        $application = HelpApplication::factory()
            ->for($applicant, 'applicant')
            ->assignedTo($category, $administrator)
            ->create([
                'reviewed_by' => $administrator->id,
                'decided_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);

        $category->delete();
        $this->assertTrue($application->fresh()->category->trashed());
        $this->assertSame($category->id, $application->category_id);

        $administrator->delete();
        $application->refresh();
        $this->assertNull($application->category_assigned_by);
        $this->assertNull($application->reviewed_by);
        $this->assertNull($application->decided_by);
        $this->assertNull($application->updated_by);

        try {
            $applicant->delete();
            $this->fail('Applicant deletion should be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('help_applications', ['id' => $application->id]);
        }

        $this->expectException(QueryException::class);
        $category->forceDelete();
    }

    public function test_inverse_relationships_use_only_their_intended_foreign_keys(): void
    {
        $applicant = User::factory()->create();
        $otherApplicant = User::factory()->create();
        $administrator = User::factory()->admin()->create();
        $assignedCategory = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $owned = HelpApplication::factory()
            ->for($applicant, 'applicant')
            ->assignedTo($assignedCategory, $administrator)
            ->create([
                'reviewed_by' => $administrator->id,
                'decided_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
        $other = HelpApplication::factory()
            ->for($otherApplicant, 'applicant')
            ->assignedTo($otherCategory, $administrator)
            ->create();

        $this->assertSame([$owned->id], $applicant->helpApplications()->pluck('id')->all());
        $this->assertSame([$other->id], $otherApplicant->helpApplications()->pluck('id')->all());
        $this->assertSame([], $administrator->helpApplications()->pluck('id')->all());
        $this->assertSame([$owned->id], $assignedCategory->helpApplications()->pluck('help_applications.id')->all());
        $this->assertSame([$other->id], $otherCategory->helpApplications()->pluck('help_applications.id')->all());

        $assignedCategory->delete();
        $owned->unsetRelation('category');
        $this->assertTrue($owned->category->trashed());
        $this->assertSame($assignedCategory->id, $owned->category->id);

        $this->assertFalse(Schema::hasColumn('campaigns', 'help_application_id'));
        $this->assertFalse(method_exists(Campaign::class, 'helpApplication'));
        $this->assertFalse(method_exists(HelpApplication::class, 'campaign'));
    }

    public function test_requested_amount_round_trips_as_exact_strings_without_float_casts(): void
    {
        foreach (['0.01', '1000.50', '9999999999999999.99'] as $amount) {
            $application = HelpApplication::factory()->create(['requested_amount' => $amount]);
            $this->assertSame($amount, $application->fresh()->requested_amount);
            $rawAmount = DB::table('help_applications')->where('id', $application->id)->value('requested_amount');
            $this->assertIsString($rawAmount);
            $this->assertSame($amount, $rawAmount);
        }

        $casts = (new HelpApplication)->getCasts();
        $this->assertSame('decimal:2', $casts['requested_amount']);
        $this->assertNotContains($casts['requested_amount'], ['float', 'double', 'real']);
    }

    public function test_encrypted_fields_are_ciphertext_at_rest_and_decrypt_through_casts(): void
    {
        $privateValues = [
            'full_name' => 'Private Applicant Name',
            'email' => 'private-applicant@example.test',
            'phone' => '+249 111 222 333',
            'address' => 'Private applicant address',
            'date_of_birth' => '1988-02-03',
            'identity_document_number' => '00-A 12/34',
            'private_story' => 'A private story that must not become campaign copy.',
            'preferred_receiving_method' => 'General preference only.',
        ];
        $application = HelpApplication::factory()->create($privateValues);
        $raw = DB::table('help_applications')->where('id', $application->id)->first();

        foreach ($privateValues as $field => $plaintext) {
            $this->assertSame($plaintext, $application->fresh()->{$field});
            $this->assertNotSame($plaintext, $raw->{$field});
            $this->assertStringNotContainsString($plaintext, $raw->{$field});
        }
    }

    public function test_identity_issuing_country_is_stored_as_trimmed_uppercase(): void
    {
        $application = HelpApplication::factory()->create(['identity_issuing_country' => ' sd ']);

        $this->assertSame('SD', $application->identity_issuing_country);
        $this->assertSame('SD', DB::table('help_applications')->where('id', $application->id)->value('identity_issuing_country'));
    }

    public function test_blind_index_is_keyed_domain_separated_and_conservatively_normalized(): void
    {
        $this->configureBlindIndex();
        $service = app(IdentityBlindIndex::class);
        $canonical = $service->compute(IdentityDocumentType::NationalId, 'sd', '  ab-00  12  ');

        $this->assertSame($canonical, $service->compute(IdentityDocumentType::NationalId, 'SD', 'AB-00  12'));
        $this->assertSame($canonical, $service->compute(IdentityDocumentType::NationalId, 'SD', 'ab-00  12'));
        $this->assertNotSame($canonical, $service->compute(IdentityDocumentType::Passport, 'SD', 'AB-00  12'));
        $this->assertNotSame($canonical, $service->compute(IdentityDocumentType::NationalId, 'EG', 'AB-00  12'));
        $this->assertNotSame($canonical, $service->compute(IdentityDocumentType::NationalId, 'SD', 'AB-00 12'));
        $this->assertNotSame($canonical, $service->compute(IdentityDocumentType::NationalId, 'SD', 'AB0012'));
        $this->assertNotSame($canonical, $service->compute(IdentityDocumentType::NationalId, 'SD', 'AB-00  12/'));
        $this->assertNotSame(
            $service->compute(IdentityDocumentType::NationalId, 'SD', '00123'),
            $service->compute(IdentityDocumentType::NationalId, 'SD', '0123'),
        );
        $this->assertNotSame(hash('sha256', 'AB-00  12'), $canonical);
        $this->assertStringNotContainsString('AB-00  12', $canonical);
        $this->assertSame(64, strlen($canonical));
        $this->assertSame(2, $service->currentKeyVersion());
        $this->assertSame(1, $service->normalizationVersion());

        if (class_exists(\Normalizer::class)) {
            $this->assertSame(
                $service->compute(IdentityDocumentType::Passport, 'SD', "CAF\u{00C9}-01"),
                $service->compute(IdentityDocumentType::Passport, 'SD', "CAFE\u{0301}-01"),
            );
        }
    }

    public function test_blind_index_uses_dedicated_versioned_keys_and_supports_rotation(): void
    {
        $this->assertNotEmpty(config('app.key'));

        config()->set('identity.blind_index.current_version', null);
        config()->set('identity.blind_index.keys', []);

        try {
            app(IdentityBlindIndex::class)->compute(IdentityDocumentType::Passport, 'SD', 'PRIVATE-ROTATION-01');
            $this->fail('APP_KEY must never be used as a blind-index fallback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Identity blind-index configuration is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE-ROTATION-01', $exception->getMessage());
            $this->assertStringNotContainsString((string) config('app.key'), $exception->getMessage());
        }

        $this->configureBlindIndex();
        $service = app(IdentityBlindIndex::class);
        $current = $service->compute(IdentityDocumentType::Passport, 'SD', 'PRIVATE-ROTATION-01');
        $explicitCurrent = $service->compute(IdentityDocumentType::Passport, 'SD', 'PRIVATE-ROTATION-01', 2);
        $previous = $service->compute(IdentityDocumentType::Passport, 'SD', 'PRIVATE-ROTATION-01', 1);

        $this->assertSame($explicitCurrent, $current);
        $this->assertNotSame($previous, $current);
        $this->assertSame(64, strlen($previous));

        try {
            $service->compute(IdentityDocumentType::Passport, 'SD', 'PRIVATE-ROTATION-01', 99);
            $this->fail('An unavailable requested key version must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Identity blind-index configuration is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE-ROTATION-01', $exception->getMessage());
        }
    }

    public function test_invalid_blind_index_configuration_fails_closed_without_private_data(): void
    {
        $invalidConfigurations = [
            [null, []],
            [1, [1 => 'not-base64!']],
            [1, [1 => base64_encode(str_repeat('x', 31))]],
            [2, [1 => base64_encode(str_repeat('x', 32))]],
        ];

        foreach ($invalidConfigurations as [$version, $keys]) {
            config()->set('identity.blind_index.current_version', $version);
            config()->set('identity.blind_index.keys', $keys);
            $encodedSecrets = array_filter($keys, 'is_string');

            try {
                app(IdentityBlindIndex::class)->compute(IdentityDocumentType::Passport, 'SD', 'PRIVATE-123');
                $this->fail('Invalid blind-index configuration should fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Identity blind-index configuration is invalid.', $exception->getMessage());
                $this->assertStringNotContainsString('PRIVATE-123', $exception->getMessage());
                $this->assertStringNotContainsString((string) config('app.key'), $exception->getMessage());

                foreach ($encodedSecrets as $encodedSecret) {
                    $this->assertStringNotContainsString($encodedSecret, $exception->getMessage());
                    $decodedSecret = base64_decode($encodedSecret, true);

                    if (is_string($decodedSecret)) {
                        $this->assertStringNotContainsString($decodedSecret, $exception->getMessage());
                    }
                }
            }
        }
    }

    public function test_ordinary_mass_assignment_changes_no_private_or_protected_attribute(): void
    {
        $application = HelpApplication::factory()->create();
        $original = $application->getAttributes();

        try {
            $application->fill([
                'reference' => (string) Str::uuid(),
                'applicant_id' => 999999,
                'category_id' => 999999,
                'status' => HelpApplicationStatus::Completed->value,
                'open_slot' => null,
                'requested_amount' => '999999.99',
                'full_name' => 'Injected name',
                'email' => 'injected@example.test',
                'phone' => 'Injected phone',
                'address' => 'Injected address',
                'date_of_birth' => '2000-01-01',
                'identity_document_type' => IdentityDocumentType::Passport->value,
                'identity_issuing_country' => 'EG',
                'identity_document_number' => 'Injected identity',
                'identity_blind_index' => str_repeat('a', 64),
                'identity_blind_index_version' => 99,
                'private_story' => 'Injected private story',
                'preferred_receiving_method' => 'Injected preference',
                'public_identity_preference' => PublicIdentityPreference::FullName->value,
                'consent_version' => HelpApplication::CONSENT_VERSION,
                'consented_at' => now(),
                'category_assigned_by' => 999999,
                'category_assigned_at' => now(),
                'reviewed_by' => 999999,
                'review_started_at' => now(),
                'decided_by' => 999999,
                'decided_at' => now(),
                'submitted_at' => now(),
                'status_changed_at' => now(),
                'appeal_eligibility_ended_at' => now(),
                'updated_by' => 999999,
                'created_at' => now()->addYear(),
                'updated_at' => now()->addYear(),
            ]);
            $this->fail('A fully guarded private aggregate must reject ordinary fill().');
        } catch (MassAssignmentException) {
            // Rejection is the configured fail-closed behavior.
        }

        $this->assertSame($original, $application->getAttributes());
        $this->assertFalse($application->isDirty());
    }

    public function test_sensitive_values_are_hidden_and_model_has_no_soft_delete_behavior(): void
    {
        $application = HelpApplication::factory()->create([
            'identity_blind_index' => str_repeat('b', 64),
        ]);
        $serialized = $application->toArray();

        foreach ([
            'full_name', 'email', 'phone', 'address', 'date_of_birth',
            'identity_document_type', 'identity_issuing_country', 'identity_document_number',
            'identity_blind_index', 'identity_blind_index_version', 'requested_amount',
            'private_story', 'preferred_receiving_method', 'public_identity_preference',
            'consent_version', 'consented_at',
        ] as $hiddenField) {
            $this->assertArrayNotHasKey($hiddenField, $serialized);
        }

        $this->assertSame(['*'], (new HelpApplication)->getGuarded());
        $this->assertFalse((new ReflectionClass(HelpApplication::class))->hasMethod('bootSoftDeletes'));
    }

    public function test_private_scopes_filter_and_order_deterministically(): void
    {
        $applicant = User::factory()->create();
        $older = HelpApplication::factory()->for($applicant, 'applicant')->pending()->create([
            'submitted_at' => now()->subDays(2),
        ]);
        $otherApplicant = HelpApplication::factory()->pending()->create([
            'submitted_at' => now()->subDay(),
        ]);
        $terminal = HelpApplication::factory()->completed()->create();

        $this->assertSame([$older->id], HelpApplication::open()->forApplicant($applicant)->pluck('id')->all());
        $this->assertSame([$terminal->id], HelpApplication::terminal()->pluck('id')->all());
        $this->assertSame([$older->id, $otherApplicant->id], HelpApplication::inStatus(HelpApplicationStatus::Pending)->inReviewOrder()->pluck('id')->all());
    }

    private function configureBlindIndex(): void
    {
        config()->set('identity.blind_index.current_version', 2);
        config()->set('identity.blind_index.keys', [
            1 => base64_encode(str_repeat('previous-test-key-material-', 2)),
            2 => base64_encode(str_repeat('current-test-key-material-', 2)),
        ]);
    }
}
