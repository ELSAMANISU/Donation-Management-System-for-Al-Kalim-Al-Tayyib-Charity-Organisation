<?php

namespace Tests\Feature\Campaign;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublished_impact_is_private_then_publication_is_immutable_and_expiry_does_not_hide_completion(): void
    {
        $admin = User::factory()->admin()->create();
        $application = HelpApplication::factory()->create(['status' => 'completed', 'open_slot' => null]);
        $campaign = Campaign::factory()->completed()->expired()->create([
            'help_application_id' => $application->id, 'category_id' => $application->category_id ?? Category::factory()->create()->id,
            'impact_update_ar' => null, 'impact_update_en' => null,
        ]);
        $public = route('cases.show', ['locale' => 'en', 'campaign' => $campaign->slug]);
        $this->get($public)->assertOk()->assertDontSee('Synthetic public impact.');
        $this->actingAs($admin);
        $edit = route('admin.campaigns.impact.edit', $campaign);
        $token = $this->get($edit)->viewData('draftToken');
        $this->post(route('admin.campaigns.impact.draft', $campaign), [
            'impact_token' => $token, 'impact_update_ar' => 'أثر تجريبي عام.', 'impact_update_en' => 'Synthetic public impact.',
        ])->assertRedirect();
        $this->assertNull($campaign->fresh()->impact_published_at);
        $this->get($public)->assertDontSee('Synthetic public impact.');
        $token = $this->get($edit)->viewData('publishToken');
        $this->post(route('admin.campaigns.impact.publish', $campaign), ['impact_token' => $token])->assertRedirect();
        $this->assertNotNull($campaign->fresh()->impact_published_at);
        $this->get($public)->assertSee('Synthetic public impact.');
        $this->post(route('admin.campaigns.impact.publish', $campaign), ['impact_token' => $token])->assertNotFound();
        $this->assertSame('Synthetic public impact.', $campaign->fresh()->impact_update_en);
    }

    public function test_impact_rejects_private_markers_and_non_administrators(): void
    {
        $owner = User::factory()->user()->create();
        $application = HelpApplication::factory()->create(['applicant_id' => $owner->id, 'status' => 'completed', 'open_slot' => null]);
        $campaign = Campaign::factory()->completed()->create(['help_application_id' => $application->id,
            'impact_update_ar' => null, 'impact_update_en' => null]);
        $edit = route('admin.campaigns.impact.edit', $campaign);
        $this->actingAs($owner)->get($edit)->assertNotFound();
        $this->actingAs(User::factory()->admin()->create());
        $token = $this->get($edit)->viewData('draftToken');
        $this->post(route('admin.campaigns.impact.draft', $campaign), [
            'impact_token' => $token, 'impact_update_ar' => 'أثر تجريبي عام.', 'impact_update_en' => 'Contact test@example.com',
        ])->assertNotFound();
        $this->assertNull($campaign->fresh()->impact_update_ar);
        $this->assertNull($campaign->fresh()->impact_published_at);
    }
}
