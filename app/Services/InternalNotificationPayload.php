<?php

namespace App\Services;

use App\Enums\InternalNotificationType;
use InvalidArgumentException;

final class InternalNotificationPayload
{
    public function isCoordination(InternalNotificationType $type): bool
    {
        return in_array($type, [InternalNotificationType::CoordinationStarted, InternalNotificationType::CoordinationResponseSubmitted,
            InternalNotificationType::CoordinationChangesRequested, InternalNotificationType::CoordinationConfirmed, InternalNotificationType::CoordinationMessageAvailable], true);
    }

    /** @return array<string, string> */
    public function build(InternalNotificationType $type, string $applicationReference): array
    {
        if ($this->isCoordination($type)) {
            return $this->validateCoordination($type, ['coordination_reference' => $applicationReference, 'state' => $this->coordinationState($type)]);
        }
        if ($type === InternalNotificationType::CampaignFundingCompleted) {
            return $this->validate($type, ['funding_reference' => $applicationReference, 'status' => 'funded']);
        }

        return $this->validate($type, [
            'application_reference' => $applicationReference,
            'status' => $this->status($type),
        ]);
    }

    /** @return array<string, string> */
    public function validate(InternalNotificationType $type, mixed $payload): array
    {
        if ($this->isCoordination($type)) {
            return $this->validateCoordination($type, $payload);
        }
        if ($type === InternalNotificationType::CampaignFundingCompleted) {
            if (! is_array($payload) || count($payload) !== 2 || ! isset($payload['funding_reference'], $payload['status'])
                || ! is_string($payload['funding_reference']) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload['funding_reference']) !== 1
                || $payload['status'] !== 'funded') {
                throw $this->invalid();
            }

            return ['funding_reference' => $payload['funding_reference'], 'status' => 'funded'];
        }
        if (! in_array($type, [
            InternalNotificationType::HelpApplicationSubmissionConfirmation,
            InternalNotificationType::HelpApplicationNewSubmission,
            InternalNotificationType::HelpApplicationApproved,
            InternalNotificationType::HelpApplicationRejected,
            InternalNotificationType::HelpApplicationCampaignActivated,
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
            || $status !== $this->status($type)) {
            throw $this->invalid();
        }

        return ['application_reference' => $reference, 'status' => $status];
    }

    private function status(InternalNotificationType $type): string
    {
        return match ($type) {
            InternalNotificationType::HelpApplicationCampaignActivated => 'campaign_active',
            InternalNotificationType::HelpApplicationApproved => 'approved',
            InternalNotificationType::HelpApplicationRejected => 'rejected',
            InternalNotificationType::HelpApplicationSubmissionConfirmation,
            InternalNotificationType::HelpApplicationNewSubmission => 'pending',
        };
    }

    private function coordinationState(InternalNotificationType $type): string
    {
        return match ($type) {
            InternalNotificationType::CoordinationStarted => 'awaiting_applicant',
            InternalNotificationType::CoordinationResponseSubmitted => 'applicant_responded',
            InternalNotificationType::CoordinationChangesRequested => 'changes_requested',
            InternalNotificationType::CoordinationConfirmed => 'confirmed',
            InternalNotificationType::CoordinationMessageAvailable => 'message_available',
        };
    }

    private function validateCoordination(InternalNotificationType $type, mixed $payload): array
    {
        if (! is_array($payload) || count($payload) !== 2
            || ! is_string($payload['coordination_reference'] ?? null)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload['coordination_reference']) !== 1
            || ($payload['state'] ?? null) !== $this->coordinationState($type)) {
            throw $this->invalid();
        }

        return ['coordination_reference' => $payload['coordination_reference'], 'state' => $payload['state']];
    }

    private function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('Internal notification payload is invalid.');
    }
}
