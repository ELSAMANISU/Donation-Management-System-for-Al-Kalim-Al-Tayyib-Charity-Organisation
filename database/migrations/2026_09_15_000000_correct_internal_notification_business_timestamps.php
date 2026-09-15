<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run with notification producers/projectors paused. MODIFY retains the current
     * session's displayed wall values, including ambiguous schedules. Do not change
     * the session timezone: legacy producers wrote UTC wall values into TIMESTAMP.
     * No table, row, index or foreign key is removed. UTC DATETIME values thereafter
     * come exclusively from the application, not the database clock.
     */
    public function up(): void
    {
        Schema::table('internal_notification_events', fn (Blueprint $table) => $table->dateTime('occurred_at')->nullable(false)->default(null)->change());
        Schema::table('internal_notification_event_recipients', fn (Blueprint $table) => $table->dateTime('available_at')->nullable(false)->default(null)->change());
        Log::info('Internal notification timestamp repair counts.', $this->repairHistoricalTimestamps());
    }

    public function down(): void
    {
        // A TIMESTAMP reversal can reinterpret wall values, exceed its date range,
        // and restore implicit auto-update. Repaired history cannot be invented back.
        // Refuse before executing anything; use a separately reviewed forward change.
        throw new RuntimeException('This timestamp repair is forward-only; DATETIME and repaired history must be retained.');
    }

    /**
     * Read-only preview: repairHistoricalTimestamps(false). Counts contain no IDs or
     * private content. Re-running after an interrupted deployment is idempotent.
     *
     * Authoritative evidence rules:
     * - The coordination UUID and application FK must identify the same coordination.
     * - Exactly one immutable transition/message must match the event's type and
     *   SHA-256(type:immutable UUID) key. Its explicit created_at is business time.
     * - Coordination producers explicitly copy that time into event/intent created_at.
     *   These must agree; creation time alone is NEVER sufficient for older producers.
     * - Any existing notification must match its intent FK, recipient, type and exact
     *   safe payload, and its creation time must agree. Older projection-time stamps
     *   or conflicting evidence are ambiguous, not grounds for manufacturing equality.
     * - Only completed events and terminal first-attempt intents are repaired.
     *   Pending schedules and terminal attempts >= 2 are retained byte-for-byte: the
     *   prior failure time/retry delay is not persisted and cannot be reconstructed.
     */
    public function repairHistoricalTimestamps(bool $apply = true): array
    {
        $empty = array_fill_keys(['repaired', 'already_correct', 'preserved_unfinished', 'preserved_retry', 'preserved_ambiguous', 'preserved_unrelated'], 0);
        $counts = ['events' => $empty, 'intents' => $empty];
        DB::table('internal_notification_events')->orderBy('id')->chunkById(100, function ($events) use ($apply, &$counts): void {
            foreach ($events as $selected) {
                DB::transaction(function () use ($selected, $apply, &$counts): void {
                    $event = DB::table('internal_notification_events')->where('id', $selected->id)->when($apply, fn ($query) => $query->lockForUpdate())->first();
                    $intents = DB::table('internal_notification_event_recipients')->where('event_id', $event->id)->orderBy('id')->when($apply, fn ($query) => $query->lockForUpdate())->get();
                    $coordinationType = str_starts_with($event->type, 'coordination_');
                    $source = $coordinationType ? $this->provenBusinessTime($event, $intents) : null;
                    $terminal = $event->projected_at !== null && $intents->every(fn ($intent) => in_array($intent->state, ['projected', 'cancelled'], true) && $intent->projected_at !== null);
                    $reason = ! $terminal ? 'preserved_unfinished' : (! $coordinationType ? 'preserved_unrelated' : ($source === null ? 'preserved_ambiguous' : null));
                    $this->classify($counts['events'], 'internal_notification_events', $event, 'occurred_at', $source, $reason, $apply);
                    foreach ($intents as $intent) {
                        $reason = ! in_array($intent->state, ['projected', 'cancelled'], true) || $intent->projected_at === null ? 'preserved_unfinished'
                            : ($intent->attempts >= 2 ? 'preserved_retry' : (! $coordinationType ? 'preserved_unrelated' : ((int) $intent->attempts !== 1 || $source === null || $intent->notification_type !== $event->type ? 'preserved_ambiguous' : null)));
                        $this->classify($counts['intents'], 'internal_notification_event_recipients', $intent, 'available_at', $source, $reason, $apply);
                    }
                });
            }
        });

        return $counts;
    }

    private function classify(array &$counts, string $table, object $row, string $field, ?string $source, ?string $reason, bool $apply): void
    {
        $category = $reason ?? ($row->{$field} === $source ? 'already_correct' : 'repaired');
        $counts[$category]++;
        if ($apply && $category === 'repaired') {
            DB::table($table)->where('id', $row->id)->update([$field => $source]);
        }
    }

    private function provenBusinessTime(object $event, $intents): ?string
    {
        $states = ['coordination_started' => 'awaiting_applicant', 'coordination_response_submitted' => 'applicant_responded', 'coordination_changes_requested' => 'changes_requested', 'coordination_confirmed' => 'confirmed', 'coordination_message_available' => 'message_available'];
        if (! isset($states[$event->type]) || $event->coordination_reference === null) {
            return null;
        }
        $coordination = DB::table('assistance_coordinations')->select(['id', 'help_application_id'])->where('reference', $event->coordination_reference)->get();
        if ($coordination->count() !== 1 || $coordination->first()->help_application_id !== $event->help_application_id) {
            return null;
        }
        $message = $event->type === 'coordination_message_available';
        $records = DB::table($message ? 'assistance_coordination_messages' : 'assistance_coordination_transitions')
            ->select(['reference', 'created_at'])->where('coordination_id', $coordination->first()->id)
            ->when(! $message, fn ($query) => $query->where('state', $states[$event->type]))->get()
            ->filter(fn ($record) => hash_equals($event->deduplication_key, hash('sha256', $event->type.':'.$record->reference)));
        if ($records->count() !== 1) {
            return null;
        }
        $record = $records->first();
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $record->reference) !== 1) {
            return null;
        }
        $source = $record->created_at;
        if (! is_string($source) || preg_match('/\A(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):[0-5]\d:[0-5]\d\z/', $source, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $event->created_at !== $source) {
            return null;
        }
        if ($event->projected_at !== null && $event->projected_at < $source) {
            return null;
        }
        foreach ($intents as $intent) {
            if ($intent->created_at !== $source || $intent->notification_type !== $event->type || ($intent->projected_at !== null && $intent->projected_at < $source)) {
                return null;
            }
            $notifications = DB::table('internal_notifications')->select(['recipient_id', 'type', 'data', 'created_at'])->where('event_recipient_id', $intent->id)->get();
            if ($notifications->count() > 1 || ($intent->state === 'projected' && $notifications->count() !== 1) || ($intent->state === 'cancelled' && $notifications->isNotEmpty())) {
                return null;
            }
            foreach ($notifications as $notification) {
                $payload = json_decode($notification->data, true);
                if ($notification->recipient_id !== $intent->recipient_id || $notification->type !== $intent->notification_type || $notification->created_at !== $source
                    || ! is_array($payload) || count($payload) !== 2 || ($payload['coordination_reference'] ?? null) !== $event->coordination_reference || ($payload['state'] ?? null) !== $states[$event->type]) {
                    return null;
                }
            }
        }

        return $source;
    }
};
