<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Builder;

final class PublicCampaignQuery
{
    public function visible(): Builder
    {
        return Campaign::query()->publiclyVisible()
            ->whereHas('category', fn ($query) => $query->select('id')->active())
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function content(string $locale, bool $detail = false): Builder
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);
        app()->setLocale($locale);
        $fields = ['id', 'category_id', 'slug', 'status', 'title_'.$locale, 'summary_'.$locale, 'image_alt_'.$locale, 'target_amount', 'raised_amount', 'published_at', 'expires_at'];
        if ($detail) {
            $fields[] = 'story_'.$locale;
            $fields[] = 'impact_published_at';
            $fields[] = 'impact_update_'.$locale;
        }

        $query = $this->visible()->select(array_values(array_diff($fields, ['impact_update_'.$locale])))->with(['category' => fn ($query) => $query->select(['id', 'slug', 'name_'.$locale])->active()]);
        if ($detail) {
            $query->selectRaw('CASE WHEN impact_published_at IS NOT NULL THEN impact_update_'.$locale.' ELSE NULL END AS public_impact');
        }

        return $query;
    }
}
