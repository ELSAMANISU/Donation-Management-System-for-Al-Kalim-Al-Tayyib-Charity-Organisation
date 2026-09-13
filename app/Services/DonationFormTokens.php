<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DonationFormTokens
{
    private const SESSION_KEY = 'donation_form_tokens';

    public function issue(Request $request, Campaign $campaign): string
    {
        $entries = $this->prune($request);
        while (count($entries) >= 32) {
            array_shift($entries);
        }
        $token = bin2hex(random_bytes(32));
        $entries[hash('sha256', $token)] = ['campaign_id' => $campaign->id, 'expires_at' => now()->addMinutes(30)->timestamp];
        $request->session()->put(self::SESSION_KEY, $entries);

        return $token;
    }

    public function entryKey(Request $request, Campaign $campaign): string
    {
        $entries = $this->prune($request);
        $token = $request->input('idempotency_token');
        $digest = is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1 ? hash('sha256', $token) : null;
        if ($digest === null || ! isset($entries[$digest]) || $entries[$digest]['campaign_id'] !== $campaign->id) {
            throw ValidationException::withMessages(['idempotency_token' => 'This donation form is invalid or expired. / نموذج التبرع غير صالح أو منتهي الصلاحية.']);
        }

        // Keep the raw form control out of persisted models and checkout capability URLs.
        return hash_hmac('sha256', $digest."\0".$campaign->id, $request->session()->token());
    }

    private function prune(Request $request): array
    {
        $entries = array_filter($request->session()->get(self::SESSION_KEY, []), fn (array $entry) => $entry['expires_at'] > now()->timestamp);
        $request->session()->put(self::SESSION_KEY, $entries);

        return $entries;
    }
}
