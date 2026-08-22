<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class AuditLogger
{
    private const MAX_USER_AGENT_LENGTH = 1024;

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'secret',
        'api_key',
        'card_number',
        'cvv',
        'account_identifier',
        'file',
        'file_content',
        'file_contents',
        'uploaded_file',
        'credentials',
        'session_id',
        'session_identifier',
        'financial_account_identifier',
    ];

    public function log(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): AuditLog {
        if (strlen($action) > 100 || ! preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:_[a-z0-9]+)*)+\z/', $action)) {
            throw new InvalidArgumentException('Audit action must be a canonical dot-delimited name.');
        }

        $auditLog = new AuditLog;
        $auditLog->actor_id = $actor?->getKey();
        $auditLog->actor_name = $actor?->name;
        $auditLog->actor_role = $actor?->role->value;
        $auditLog->action = $action;
        $auditLog->subject_type = $subject?->getMorphClass();
        $auditLog->subject_id = $subject?->getKey();
        $auditLog->old_values = $this->filterSensitiveValues($oldValues);
        $auditLog->new_values = $this->filterSensitiveValues($newValues);
        $auditLog->ip_address = $request?->ip();
        $auditLog->user_agent = $this->truncateUserAgent($request?->userAgent());
        $auditLog->save();

        return $auditLog;
    }

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    private function filterSensitiveValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $filtered = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            if ($value instanceof UploadedFile) {
                continue;
            }

            $filtered[$key] = is_array($value)
                ? $this->filterSensitiveValues($value)
                : $value;
        }

        return $filtered;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lowercaseKey = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');

        if (in_array($lowercaseKey, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        $normalizedKey = strtolower((string) preg_replace(
            ['/([a-z0-9])([A-Z])/', '/[^a-zA-Z0-9]+/'],
            ['$1_$2', '_'],
            $key,
        ));

        return in_array(trim($normalizedKey, '_'), self::SENSITIVE_KEYS, true);
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        return $userAgent === null
            ? null
            : mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
    }
}
