<?php

namespace App\Services;

use Illuminate\Http\Request;

final class AidDeliveryFormTokens
{
    private const KEY = 'aid_delivery_form_tokens';

    public function issue(Request $request, string $application, string $coordination, string $action, ?string $delivery = null, ?int $revision = null): string
    {
        $entries = $this->entries($request);
        while (count($entries) >= 32) {
            array_shift($entries);
        }
        $token = bin2hex(random_bytes(32));
        $entries[hash('sha256', $token)] = ['actor' => $request->user()->id, 'application' => $application,
            'coordination' => $coordination, 'action' => $action, 'delivery' => $delivery, 'revision' => $revision,
            'expires_at' => now()->addMinutes(30)->timestamp];
        $request->session()->put(self::KEY, $entries);

        return $token;
    }

    public function key(Request $request, string $action, array $input): string
    {
        $token = $input['idempotency_token'] ?? null;
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1, 404);
        $digest = hash('sha256', $token);
        $entry = $this->entries($request)[$digest] ?? null;
        abort_unless($entry && $entry['actor'] === $request->user()->id
            && $entry['application'] === $request->route('helpApplication') && $entry['coordination'] === $request->route('coordination')
            && $entry['action'] === $action && $entry['delivery'] === $request->route('delivery')
            && ($action === 'start' || (is_string($input['revision'] ?? null) && (string) $entry['revision'] === $input['revision'])), 404);

        // Persist only a session-bound derivation; raw controls never enter models.
        return hash_hmac('sha256', $digest, $request->session()->token());
    }

    private function entries(Request $request): array
    {
        $entries = array_filter($request->session()->get(self::KEY, []), fn ($entry) => $entry['expires_at'] > now()->timestamp);
        $request->session()->put(self::KEY, $entries);

        return $entries;
    }
}
