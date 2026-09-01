<?php

use App\Enums\InternalNotificationProjectionState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_notification_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('type', 64);
            $table->foreignId('help_application_id');
            $table->char('deduplication_key', 64)->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('projected_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'projected_at', 'id'], 'internal_notification_events_projection_index');
            $table->index(['help_application_id', 'id'], 'internal_notification_events_application_index');
            $table->foreign('help_application_id')->references('id')->on('help_applications')->restrictOnDelete();
        });

        Schema::create('internal_notification_event_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id');
            $table->foreignId('recipient_id')->nullable();
            $table->string('recipient_role', 32);
            $table->string('audience', 16);
            $table->string('notification_type', 64);
            $table->string('state', 16)->default(InternalNotificationProjectionState::Pending->value);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event_id', 'recipient_id', 'notification_type'], 'internal_notification_recipients_event_user_type_unique');
            $table->index(['state', 'available_at', 'id'], 'internal_notification_recipients_ready_index');
            $table->index(['recipient_id', 'state', 'id'], 'internal_notification_recipients_user_state_index');
            $table->foreign('event_id')->references('id')->on('internal_notification_events')->restrictOnDelete();
            $table->foreign('recipient_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('internal_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('event_recipient_id')->unique();
            $table->foreignId('recipient_id')->nullable();
            $table->string('type', 64);
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_id', 'read_at', 'created_at', 'id'], 'internal_notifications_recipient_read_index');
            $table->foreign('event_recipient_id')->references('id')->on('internal_notification_event_recipients')->restrictOnDelete();
            $table->foreign('recipient_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notifications');
        Schema::dropIfExists('internal_notification_event_recipients');
        Schema::dropIfExists('internal_notification_events');
    }
};
