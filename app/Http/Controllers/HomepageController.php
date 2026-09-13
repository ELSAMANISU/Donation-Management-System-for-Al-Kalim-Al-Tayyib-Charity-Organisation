<?php

namespace App\Http\Controllers;

use App\Services\PublicCampaignQuery;

class HomepageController extends Controller
{
    public function __invoke(PublicCampaignQuery $query, string $locale = 'en')
    {
        $cases = $query->content($locale)->inPriorityOrder()->limit(4)->get();

        return view('welcome', compact('cases', 'locale'));
    }
}
