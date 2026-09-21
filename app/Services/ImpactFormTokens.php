<?php

namespace App\Services;

use Illuminate\Http\Request;

final class ImpactFormTokens
{
    private const KEY = 'impact_form_tokens';

    public function issue(Request $request, string $slug, string $action, string $version): string
    {
        $entries = $this->entries($request);
        while (count($entries) >= 32) {
            array_shift($entries);
        }
        $token = bin2hex(random_bytes(32));
        $entries[hash('sha256', $token)] = ['actor' => $request->user()->id, 'slug' => $slug,
            'action' => $action, 'version' => $version, 'expires_at' => now()->addMinutes(30)->timestamp];
        $request->session()->put(self::KEY, $entries);

        return $token;
    }

    public function consume(Request $request, string $action): string
    {
        $token = $request->input('impact_token');
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token), 404);
        $entries = $this->entries($request);
        $digest = hash('sha256', $token);
        $entry = $entries[$digest] ?? null;
        abort_unless($entry && $entry['actor'] === $request->user()->id && $entry['slug'] === $request->route('campaign')->slug
            && $entry['action'] === $action, 404);
        unset($entries[$digest]);
        $request->session()->put(self::KEY, $entries);

        return $entry['version'];
    }

    private function entries(Request $request): array
    {
        $entries = array_filter($request->session()->get(self::KEY, []), fn ($entry) => $entry['expires_at'] > now()->timestamp);
        $request->session()->put(self::KEY, $entries);

        return $entries;
    }
}
