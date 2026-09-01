<?php

namespace App\Services;

use App\Enums\InternalNotificationType;
use InvalidArgumentException;

final class InternalNotificationPayload
{
    /** @return array{application_reference: string, status: string} */
    public function build(InternalNotificationType $type, string $applicationReference): array
    {
        return $this->validate($type, [
            'application_reference' => $applicationReference,
            'status' => 'pending',
        ]);
    }

    /** @return array{application_reference: string, status: string} */
    public function validate(InternalNotificationType $type, mixed $payload): array
    {
        if (! in_array($type, [
            InternalNotificationType::HelpApplicationSubmissionConfirmation,
            InternalNotificationType::HelpApplicationNewSubmission,
        ], true) || ! is_array($payload) || array_is_list($payload) || count($payload) !== 2) {
            throw $this->invalid();
        }

        $keys = array_keys($payload);
        sort($keys);

        if ($keys !== ['application_reference', 'status']) {
            throw $this->invalid();
        }

        $reference = $payload['application_reference'] ?? null;
        $status = $payload['status'] ?? null;

        if (! is_string($reference)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $reference) !== 1
            || $status !== 'pending') {
            throw $this->invalid();
        }

        return ['application_reference' => $reference, 'status' => $status];
    }

    private function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('Internal notification payload is invalid.');
    }
}
