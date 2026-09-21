<?php

namespace App\Services;

use Illuminate\Http\Request;

final class CompletionFormTokens
{
    private const KEY = 'completion_form_tokens';

    public function issue(Request $request, string $application, string $coordination, int $revision): string
    {
        $entries = $this->entries($request);
        while (count($entries) >= 32) {
            array_shift($entries);
        }
        $token = bin2hex(random_bytes(32));
        $entries[hash('sha256', $token)] = ['actor' => $request->user()->id, 'application' => $application,
            'coordination' => $coordination, 'revision' => $revision, 'action' => 'complete',
            'expires_at' => now()->addMinutes(30)->timestamp];
        $request->session()->put(self::KEY, $entries);

        return $token;
    }

    public function consume(Request $request): int
    {
        $keys = array_keys($request->request->all());
        sort($keys);
        abort_unless($request->query->count() === 0 && $request->files->count() === 0
            && in_array($keys, [['_token', 'completion_token'], ['completion_token']], true), 404);
        $token = $request->input('completion_token');
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token), 404);
        $entries = $this->entries($request);
        $digest = hash('sha256', $token);
        $entry = $entries[$digest] ?? null;
        abort_unless($entry && $entry['actor'] === $request->user()->id
            && $entry['application'] === $request->route('helpApplication')
            && $entry['coordination'] === $request->route('coordination') && $entry['action'] === 'complete', 404);
        unset($entries[$digest]);
        $request->session()->put(self::KEY, $entries);

        return $entry['revision'];
    }

    private function entries(Request $request): array
    {
        $entries = array_filter($request->session()->get(self::KEY, []), fn ($entry) => $entry['expires_at'] > now()->timestamp);
        $request->session()->put(self::KEY, $entries);

        return $entries;
    }
}
