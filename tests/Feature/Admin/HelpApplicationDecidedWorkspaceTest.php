<?php

namespace Tests\Feature\Admin;

use App\Enums\HelpApplicationStatus;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use App\Services\HelpApplicationCampaignConversionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HelpApplicationDecidedWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->freezeSecond();
        $actor = User::factory()->admin()->create(['name' => 'Historical Reviewer']);
        $application = HelpApplication::factory()->approved()->assignedTo(Category::factory()->create(), $actor)->create([
            'reviewed_by' => $actor->id, 'decided_by' => $actor->id, 'decided_at' => now(), 'status_changed_at' => now(),
            'decision_note' => '<script>historical private note</script>', 'full_name' => 'Historical applicant',
        ]);

        return [$actor, $application->refresh()];
    }

    private function url(HelpApplication $application): string
    {
        return route('admin.help-applications.decided.show', $application->reference);
    }

    private function index(array $query = []): string
    {
        return route('admin.help-applications.decided.index', $query);
    }

    private function convert(User $actor, HelpApplication $application): Campaign
    {
        return app(HelpApplicationCampaignConversionService::class)->convert($actor, $application->reference, [
            'slug' => 'historical-draft', 'title_ar' => 'عنوان عام', 'title_en' => 'Public title', 'summary_ar' => 'ملخص',
            'summary_en' => 'Summary', 'story_ar' => 'قصة عامة', 'story_en' => 'Public story', 'target_amount' => '10.00',
        ])->campaign;
    }

    public function test_exact_three_routes_methods_middleware_uuid_order_and_no_extra_mutations(): void
    {
        $all = collect(app('router')->getRoutes())->values();
        $routes = $all->filter(fn ($r) => str_starts_with($r->uri(), 'admin/help-applications/decided'))->values();
        $this->assertCount(3, $routes);
        foreach (['index', 'show', 'convert-to-campaign'] as $i => $suffix) {
            $route = $routes[$i];
            $this->assertSame('admin.help-applications.decided.'.$suffix, $route->getName());
            $this->assertSame($i === 2 ? ['POST'] : ['GET', 'HEAD'], $route->methods());
            $this->assertSame($i === 2 ? ['web', 'auth', 'role:admin,super_admin', 'throttle:10,1'] : ['web', 'auth', 'role:admin,super_admin'], $route->gatherMiddleware());
            $this->assertSame('admin/help-applications/decided'.($i ? '/{helpApplication}' : '').($i === 2 ? '/convert-to-campaign' : ''), $route->uri());
            if ($i) {
                $this->assertSame(['helpApplication' => '[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}'], $route->wheres);
            }
            $this->assertLessThan($all->search(fn ($r) => $r->getName() === 'admin.help-applications.show'), $all->search(fn ($r) => $r === $route));
        }
    }

    public function test_get_account_boundaries_follow_existing_administrator_conventions(): void
    {
        [$actor, $application] = $this->fixture();
        foreach ([$this->index(), $this->url($application)] as $url) {
            $this->get($url)->assertRedirect(route('login'))->assertDontSee('Historical applicant');
        }
        foreach ([$this->index(), $this->url($application)] as $url) {
            $this->actingAs(User::factory()->user()->create())->get($url)->assertForbidden();
            $this->actingAs(User::factory()->admin()->disabled()->create())->get($url)->assertRedirect(route('login'));
            $this->actingAs(User::factory()->admin()->mustChangePassword()->create())->get($url)->assertRedirect(route('password.change.required.edit'));
        }
    }

    public function test_index_scope_filters_historical_statuses_and_minimal_projection(): void
    {
        [$actor, $approved] = $this->fixture();
        $rejected = HelpApplication::factory()->rejected()->create(['reviewed_by' => $actor->id]);
        $converted = HelpApplication::factory()->convertedToCampaign()->create(['reviewed_by' => $actor->id]);
        $other = HelpApplication::factory()->approved()->create(['reviewed_by' => User::factory()->admin()->create()->id]);
        $orphan = HelpApplication::factory()->approved()->create(['reviewed_by' => null]);
        $response = $this->actingAs($actor)->get($this->index())->assertOk()->assertSee($approved->reference)->assertSee($rejected->reference)->assertSee($converted->reference)
            ->assertDontSee($other->reference)->assertDontSee($orphan->reference);
        $this->assertSame(['reference', 'status', 'full_name', 'submitted_at', 'review_started_at', 'decided_at'], array_keys($response->viewData('applications')->first()->getAttributes()));
        $this->get($this->index(['status' => 'approved']))->assertOk()->assertSee($approved->reference)->assertSee($converted->reference)->assertDontSee($rejected->reference);
        $this->get($this->index(['status' => 'rejected']))->assertOk()->assertSee($rejected->reference)->assertDontSee($approved->reference)->assertDontSee($converted->reference);
        $this->get($this->index(['search' => 'anything', 'reviewed_by' => $other->reviewed_by, 'sort' => 'id']))->assertOk()->assertSee($approved->reference)->assertDontSee($other->reference);
        foreach (['all', '', 'APPROVED', 'approved ', ['approved'], 'pending'] as $invalid) {
            $this->get($this->index(['status' => $invalid]))->assertNotFound();
        }
        $response = $this->actingAs(User::factory()->superAdmin()->create())->get($this->index())->assertOk()->assertSee($other->reference)->assertSee($orphan->reference)->assertSee('Reviewer unavailable / المسؤول غير متاح');
        $this->assertSame(['reference', 'status', 'full_name', 'submitted_at', 'review_started_at', 'decided_at', 'reviewer_name'], array_keys($response->viewData('applications')->first()->getAttributes()));
    }

    public function test_ordering_ties_pagination_and_canonical_filter_links(): void
    {
        [$actor, $application] = $this->fixture();
        $expected = [];
        for ($i = 0; $i < 27; $i++) {
            $expected[] = HelpApplication::factory()->approved()->create(['reviewed_by' => $actor->id, 'decided_at' => now()->addMinutes(intdiv($i, 2) + 1)])->reference;
        }
        $response = $this->actingAs($actor)->get($this->index(['status' => 'approved', 'search' => 'PRIVATE-QUERY']))->assertOk();
        $page = $response->viewData('applications');
        $this->assertSame(25, $page->perPage());
        $this->assertCount(25, $page);
        $this->assertSame(array_slice(array_reverse($expected), 0, 25), $page->pluck('reference')->all());
        $response->assertDontSee('PRIVATE-QUERY');
        $this->get($this->index(['status' => 'approved', 'page' => 2]))->assertOk()->assertSee($application->reference);
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status->value], HelpApplicationStatus::cases());
    }

    #[DataProvider('statuses')]
    public function test_exact_status_allowlist_on_index_and_detail(string $status): void
    {
        [$actor, $application] = $this->fixture();
        if ($status === 'converted_to_campaign') {
            $this->convert($actor, $application);
        } else {
            DB::table('help_applications')->where('id', $application->id)->update(['status' => $status]);
        }
        $this->actingAs($actor);
        if (in_array($status, ['approved', 'rejected', 'converted_to_campaign'], true)) {
            $this->get($this->index())->assertOk()->assertSee($application->reference);
            $this->get($this->url($application))->assertOk();
        } else {
            $this->get($this->index())->assertOk()->assertDontSee($application->reference);
            $this->get($this->url($application))->assertNotFound();
        }
    }

    public function test_detail_conceals_foreign_orphan_missing_and_malformed_references(): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs(User::factory()->admin()->create())->get($this->url($application))->assertNotFound();
        DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => null]);
        $this->actingAs($actor)->get($this->url($application))->assertNotFound();
        foreach (['bad', '123', (string) Str::uuid()] as $reference) {
            $this->get('/admin/help-applications/decided/'.$reference)->assertNotFound();
        }
        $this->actingAs(User::factory()->superAdmin()->create())->get($this->url($application))->assertOk()->assertSee('Reviewer unavailable / المسؤول غير متاح');
    }

    public function test_get_and_head_use_exact_private_headers_for_both_roles_and_both_pages(): void
    {
        [$actor, $application] = $this->fixture();
        foreach ([$actor, User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user);
            foreach ([$this->index(), $this->url($application)] as $url) {
                foreach (['GET', 'HEAD'] as $method) {
                    $this->call($method, $url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
                }
            }
        }
    }

    public function test_historical_fields_escaping_document_metadata_and_warning_aggregates_only(): void
    {
        [$actor, $application] = $this->fixture();
        $document = HelpApplicationDocument::factory()->medicalReport()->acceptedUnscanned()->create(['help_application_id' => $application->id, 'original_name' => '<script>evidence.pdf</script>']);
        $removed = HelpApplicationDocument::factory()->medicalReport()->removedBy($actor)->create(['help_application_id' => $application->id, 'original_name' => 'REMOVED.pdf']);
        HelpApplicationDocument::factory()->medicalReport()->create(['original_name' => 'FOREIGN.pdf']);
        $warning = HelpApplicationDuplicateWarning::factory()->confirmed()->create(['submitted_application_id' => $application->id, 'resolution_note' => 'SECRET RESOLUTION']);
        HelpApplicationDuplicateWarning::factory()->dismissed()->create(['submitted_application_id' => $application->id]);
        HelpApplicationDuplicateWarning::factory()->create(['submitted_application_id' => $application->id]);
        DB::table('help_applications')->where('id', $application->id)->update(['identity_document_number' => 'intentionally corrupt excluded ciphertext']);
        $page = $this->actingAs($actor)->get($this->url($application))->assertOk()->assertSee('<script>historical private note</script>')->assertDontSee('<script>historical private note</script>', false)
            ->assertSee('<script>evidence.pdf</script>')->assertDontSee('<script>evidence.pdf</script>', false)
            ->assertSee('Confirmed match / تطابق مؤكد: 1')->assertSee('Dismissed / مستبعد: 1')->assertSee('Unreviewed / لم تتم المراجعة: 1');
        foreach (['full_name', 'email', 'phone', 'address', 'date_of_birth', 'private_story', 'preferred_receiving_method'] as $field) {
            $page->assertSee($application->{$field});
        }
        foreach ([$document->reference, $document->storage_path, $document->checksum, $document->mime_type, $warning->reference, $warning->matchedApplication?->reference ?? 'SECRET RESOLUTION', 'SECRET RESOLUTION', 'REMOVED.pdf', 'FOREIGN.pdf', 'intentionally corrupt excluded ciphertext'] as $private) {
            $page->assertDontSee($private);
        }
        $this->assertSame(['original_name', 'extension', 'size_bytes', 'purpose', 'security_status', 'created_at'], array_keys($page->viewData('documents')->sole()->getAttributes()));
        foreach (['assign-category', '/decide"', 'duplicate-warnings', '/download', '/preview', '/publish', '/donations', '/payments', '/delivery'] as $action) {
            $page->assertDontSee($action, false);
        }
    }

    public function test_oversight_names_unavailable_labels_and_inactive_deleted_category(): void
    {
        [$actor, $application] = $this->fixture();
        $category = $application->category;
        $category->is_active = false;
        $category->save();
        $category->delete();
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->get($this->url($application))->assertOk()->assertSee('Historical Reviewer')->assertSee('Unavailable for new assignments');
        DB::table('help_applications')->where('id', $application->id)->update(['reviewed_by' => null, 'decided_by' => null]);
        $this->get($this->url($application))->assertOk()->assertSee('Reviewer unavailable / المسؤول غير متاح')->assertSee('Decision-maker unavailable / صاحب القرار غير متاح')->assertDontSee('name="story_en"', false);
    }

    public function test_corrupt_decision_note_is_concealed_without_partial_private_output(): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('help_applications')->where('id', $application->id)->update(['decision_note' => 'corrupt']);
        $this->actingAs($actor)->get($this->url($application))->assertNotFound()->assertDontSee('Historical applicant')->assertDontSee('corrupt');
    }

    public function test_approved_form_is_single_blank_public_form_with_fixed_category_and_privacy_warning(): void
    {
        [$actor, $application] = $this->fixture();
        $page = $this->actingAs($actor)->get($this->url($application))->assertOk();
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, 'action="'.route('admin.help-applications.decided.convert-to-campaign', $application->reference).'"'));
        $page->assertSee('Privacy warning:')->assertSee('name="_token"', false)->assertDontSee('name="category_id"', false);
        foreach (['summary_ar', 'summary_en', 'story_ar', 'story_en'] as $field) {
            $this->assertMatchesRegularExpression('/<textarea[^>]*name="'.$field.'"[^>]*><\/textarea>/', $html);
        }
        foreach (['title_ar', 'title_en', 'slug', 'target_amount'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="'.$field.'"[^>]*value=""/', $html);
        }
    }

    public function test_rejected_has_note_and_deadline_and_converted_has_exactly_one_editor_link_and_no_conversion(): void
    {
        [$actor, $application] = $this->fixture();
        DB::table('help_applications')->where('id', $application->id)->update(['status' => 'rejected', 'appeal_eligibility_ended_at' => '2026-10-10 12:00:00']);
        $this->actingAs($actor)->get($this->url($application))->assertOk()->assertSee('2026-10-10 12:00')->assertSee('historical private note')->assertDontSee('name="story_en"', false);
        DB::table('help_applications')->where('id', $application->id)->update(['status' => 'approved', 'appeal_eligibility_ended_at' => null]);
        $campaign = $this->convert($actor, $application);
        $page = $this->get($this->url($application))->assertOk()->assertSee('historical-draft')->assertSee('Draft / مسودة')->assertDontSee('name="story_en"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'href="'.route('admin.campaigns.edit', $campaign).'"'));
        $this->actingAs($application->applicant)->get(route('help-applications.index'))->assertOk()->assertDontSee('historical private note')->assertDontSee('historical-draft');
        $this->get('/en/cases')->assertOk()->assertDontSee('historical-draft')->assertDontSee('historical private note');
    }

    public static function inconsistentLinks(): array
    {
        return [['missing'], ['deleted'], ['category'], ['status'], ['published'], ['raised'], ['excessive target']];
    }

    #[DataProvider('inconsistentLinks')]
    public function test_inconsistent_converted_relationship_fails_closed(string $kind): void
    {
        [$actor, $application] = $this->fixture();
        $campaign = $this->convert($actor, $application);
        match ($kind) {
            'missing' => DB::table('campaigns')->where('id', $campaign->id)->update(['help_application_id' => null]),
            'deleted' => $campaign->delete(),
            'category' => DB::table('campaigns')->where('id', $campaign->id)->update(['category_id' => Category::factory()->create()->id]),
            'status' => DB::table('campaigns')->where('id', $campaign->id)->update(['status' => 'active']),
            'published' => DB::table('campaigns')->where('id', $campaign->id)->update(['published_at' => now()]),
            'raised' => DB::table('campaigns')->where('id', $campaign->id)->update(['raised_amount' => '1.00']),
            'excessive target' => DB::table('campaigns')->where('id', $campaign->id)->update(['target_amount' => '1000.51']),
        };
        $this->actingAs($actor)->get($this->url($application))->assertNotFound()->assertDontSee('Historical applicant');
    }

    public function test_duplicate_link_in_corrupt_database_is_concealed(): void
    {
        [$actor, $application] = $this->fixture();
        $this->convert($actor, $application);
        // Remove the index only inside this rolled-back test transaction to model corrupt legacy data.
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropUnique(['help_application_id']));
        Campaign::factory()->create(['help_application_id' => $application->id, 'category_id' => $application->category_id]);
        $this->actingAs($actor)->get($this->url($application))->assertNotFound()->assertDontSee('Historical applicant');
    }

    public function test_all_gets_are_database_immutable_and_dispatch_no_side_effects(): void
    {
        [$actor, $application] = $this->fixture();
        HelpApplicationDocument::factory()->medicalReport()->create(['help_application_id' => $application->id]);
        $tables = collect(Schema::getTables())->pluck('name')->reject(fn ($name) => $name === 'sqlite_sequence');
        $snapshot = fn () => $tables->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])->all();
        $before = $snapshot();
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Log::spy();
        Storage::shouldReceive('disk')->never();
        $this->actingAs($actor);
        foreach ([$this->index(), $this->url($application)] as $url) {
            $this->get($url)->assertOk();
            $this->call('HEAD', $url)->assertOk();
        }
        $this->assertSame($before, $snapshot());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
    }

    public static function staleReads(): array
    {
        return [['status'], ['reviewed_by']];
    }

    #[DataProvider('staleReads')]
    public function test_deterministic_single_connection_interleaving_reasserts_secondary_scope(string $field): void
    {
        [$actor, $application] = $this->fixture();
        HelpApplicationDocument::factory()->medicalReport()->create(['help_application_id' => $application->id]);
        $armed = true;
        $queries = [];
        DB::listen(function ($query) use (&$armed, &$queries, $application, $field): void {
            $queries[] = $query->sql;
            if ($armed && str_contains($query->sql, '"decision_note"') && str_contains($query->sql, 'from "help_applications"')) {
                $armed = false;
                DB::table('help_applications')->where('id', $application->id)->update([$field => $field === 'status' ? 'closed' : null]);
            }
        });
        $this->actingAs($actor)->get($this->url($application))->assertNotFound()->assertDontSee('Historical applicant');
        $this->assertFalse($armed);
        foreach ($queries as $sql) {
            if (str_contains($sql, 'from "help_application_documents"') || str_contains($sql, 'from "help_application_duplicate_warnings"') || str_contains($sql, 'from "categories"') || str_contains($sql, 'from "campaigns"')) {
                $this->assertStringContainsString('"reviewed_by" = ?', $sql);
                $this->assertStringContainsString('"status" in', $sql);
            }
        }
    }

    public function test_navigation_has_one_desktop_and_one_responsive_link_with_independent_active_states(): void
    {
        [$actor, $application] = $this->fixture();
        $this->actingAs($actor);
        foreach ([$this->index(), $this->url($application), route('admin.help-applications.index'), route('admin.help-applications.in-review.index')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach ([$this->index(), route('admin.help-applications.index'), route('admin.help-applications.in-review.index')] as $target) {
                preg_match_all('/<a class="([^"]+)" href="'.preg_quote($target, '/').'">/', $html, $matches);
                $this->assertCount(2, $matches[1]);
                foreach ($matches[1] as $classes) {
                    $this->assertSame($target === $url || ($url === $this->url($application) && $target === $this->index()), str_contains($classes, 'border-indigo-400'));
                }
            }
        }
        $this->actingAs(User::factory()->user()->create())->get('/dashboard')->assertDontSee('Decided Applications');
    }

    private function indexMarkup(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }

    public static function indexFilters(): array
    {
        return [[null], ['approved'], ['rejected']];
    }

    #[DataProvider('indexFilters')]
    public function test_index_filter_placeholder_selection_and_separate_accessible_controls(?string $status): void
    {
        [$actor] = $this->fixture();
        $response = $this->actingAs($actor)->get($this->index($status === null ? [] : ['status' => $status]))->assertOk();
        $xpath = $this->indexMarkup($response->getContent());
        $select = $xpath->query('//select[@id="status-filter"]')->item(0);
        $this->assertNotNull($select);
        $form = $select->parentNode->parentNode;
        $this->assertSame('GET', $form->getAttribute('method'));
        $this->assertSame($this->index(), $form->getAttribute('action'));
        foreach (['flex', 'flex-col', 'gap-4', 'sm:flex-row', 'sm:items-end'] as $class) {
            $this->assertContains($class, explode(' ', $form->getAttribute('class')));
        }
        $this->assertSame(1, $xpath->query('.//label[@for="status-filter"]', $form)->length);
        $options = $xpath->query('./option', $select);
        $this->assertSame(3, $options->length);
        $this->assertSame('Filter by status / تصفية حسب الحالة', trim($options->item(0)->textContent));
        $this->assertTrue($options->item(0)->hasAttribute('disabled'));
        $this->assertSame('', $options->item(0)->getAttribute('value'));
        foreach (['', 'approved', 'rejected'] as $i => $value) {
            $option = $options->item($i);
            $this->assertSame($value, $option->getAttribute('value'));
            $this->assertSame(($status ?? '') === $value, $option->hasAttribute('selected'));
            if ($i > 0) {
                $this->assertFalse($option->hasAttribute('disabled'));
            }
        }
        $this->assertSame(1, $xpath->query('./option[@selected]', $select)->length);
        $buttons = $xpath->query('.//button[@type="submit"]', $form);
        $links = $xpath->query('.//a', $form);
        $this->assertSame(1, $buttons->length);
        $this->assertSame(1, $links->length);
        $this->assertSame('Filter / تصفية', trim($buttons->item(0)->textContent));
        $this->assertSame('All / الكل', trim($links->item(0)->textContent));
        $this->assertSame($this->index(), $links->item(0)->getAttribute('href'));
        foreach ([$select, $buttons->item(0), $links->item(0)] as $control) {
            $classes = explode(' ', $control->getAttribute('class'));
            $this->assertContains('focus:ring-2', $classes);
            $this->assertContains('focus:ring-indigo-500', $classes);
            $this->assertContains('focus:ring-offset-2', $classes);
        }
        $response->assertDontSee('value="all"', false)->assertDontSee('status=all', false);
    }

    public function test_index_empty_state_has_exact_decided_copy(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get($this->index())->assertOk();
        $xpath = $this->indexMarkup($response->getContent());
        $paragraph = $xpath->query('//p[contains(., "No decided applications.")]')->item(0);
        $this->assertNotNull($paragraph);
        $this->assertSame('No decided applications. / لا توجد طلبات محسومة.', trim($paragraph->textContent));
        $response->assertDontSee('لا توجد طلبات مساعدة قيد المراجعة حاليًا.')
            ->assertDontSee('لا توجد طلبات مساعدة قيد المراجعة حالياً.');
    }

    public static function indexRoles(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('indexRoles')]
    public function test_index_table_preserves_columns_semantics_and_readable_bidi_alignment(bool $super): void
    {
        [$actor, $application] = $this->fixture();
        $response = $this->actingAs($super ? User::factory()->superAdmin()->create() : $actor)->get($this->index())->assertOk();
        $xpath = $this->indexMarkup($response->getContent());
        $table = $xpath->query('//table')->item(0);
        $this->assertNotNull($table);
        $this->assertContains('overflow-x-auto', explode(' ', $table->parentNode->getAttribute('class')));
        $this->assertSame(1, $xpath->query('./caption[@class="sr-only"]', $table)->length);
        $headers = $xpath->query('./thead/tr/th[@scope="col"]', $table);
        $cells = $xpath->query('./tbody/tr/td', $table);
        $this->assertSame($super ? 7 : 6, $headers->length);
        $this->assertSame($headers->length, $cells->length);
        foreach ($headers as $header) {
            foreach (['text-start', 'px-6', 'py-3'] as $class) {
                $this->assertContains($class, explode(' ', $header->getAttribute('class')));
            }
        }
        foreach ($cells as $cell) {
            foreach (['px-6', 'py-4'] as $class) {
                $this->assertContains($class, explode(' ', $cell->getAttribute('class')));
            }
            $this->assertSame('vertical-align: middle', $cell->getAttribute('style'));
        }
        $reference = $cells->item(1);
        $this->assertContains('break-all', explode(' ', $reference->getAttribute('class')));
        $this->assertSame($application->reference, $xpath->query('./bdi[@dir="ltr"]', $reference)->item(0)->textContent);
        foreach ([2 => $application->submitted_at, 3 => $application->decided_at] as $index => $date) {
            $cell = $cells->item($index);
            $this->assertContains('whitespace-nowrap', explode(' ', $cell->getAttribute('class')));
            $time = $xpath->query('./time', $cell)->item(0);
            $this->assertSame($date->toIso8601String(), $time->getAttribute('datetime'));
            $this->assertSame($date->format('Y-m-d H:i'), $time->textContent);
        }
        $action = $cells->item($cells->length - 1);
        foreach ([$cells->item(4), $action] as $cell) {
            foreach (['whitespace-nowrap', 'text-start'] as $class) {
                $this->assertContains($class, explode(' ', $cell->getAttribute('class')));
            }
        }
        $view = $xpath->query('./a', $action)->item(0);
        $this->assertSame($this->url($application), $view->getAttribute('href'));
        foreach (['focus:ring-2', 'focus:ring-indigo-500', 'focus:ring-offset-2'] as $class) {
            $this->assertContains($class, explode(' ', $view->getAttribute('class')));
        }
        $this->assertSame($super ? 1 : 0, $xpath->query('./thead/tr/th[contains(., "Reviewer")]', $table)->length);
        if ($super) {
            $this->assertSame('Historical Reviewer', trim($cells->item(5)->textContent));
        }
    }

    public function test_all_new_static_css_utilities_exist_in_compiled_assets(): void
    {
        $css = collect(glob(public_path('build/assets/*.css')))->map(fn ($p) => file_get_contents($p))->implode("\n");
        foreach (glob(resource_path('views/admin/help-applications/decided/*.blade.php')) as $path) {
            preg_match_all('/class="([^"]+)"/', file_get_contents($path), $matches);
            foreach ($matches[1] as $classes) {
                foreach (explode(' ', $classes) as $class) {
                    $this->assertStringContainsString('.'.str_replace([':', '/'], ['\\:', '\\/'], $class), $css, $class);
                }
            }
        }
    }
}
