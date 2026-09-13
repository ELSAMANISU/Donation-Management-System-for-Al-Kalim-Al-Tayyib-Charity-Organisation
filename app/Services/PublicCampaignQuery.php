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
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function content(string $locale, bool $detail = false): Builder
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);
        app()->setLocale($locale);
        $fields = ['id', 'category_id', 'slug', 'status', 'title_'.$locale, 'summary_'.$locale, 'image_alt_'.$locale, 'target_amount', 'raised_amount', 'published_at', 'expires_at'];
        if ($detail) {
            $fields[] = 'story_'.$locale;
        }

        return $this->visible()->select($fields)->with(['category' => fn ($query) => $query->select(['id', 'slug', 'name_'.$locale])->active()]);
    }
}
