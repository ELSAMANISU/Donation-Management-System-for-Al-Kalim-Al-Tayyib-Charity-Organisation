<?php

use App\Enums\AssistanceCoordinationState;
use App\Enums\AssistanceDeliveryMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_coordinations', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('help_application_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('state', array_column(AssistanceCoordinationState::cases(), 'value'));
            $table->unsignedInteger('revision')->default(1);
            $table->enum('delivery_method', array_column(AssistanceDeliveryMethod::cases(), 'value'))->nullable();
            $table->text('delivery_details')->nullable();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            // UTC application values; DATETIME avoids legacy TIMESTAMP auto-update and conversion.
            $table->dateTime('started_at');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->index(['state', 'id']);
        });
        Schema::create('assistance_coordination_transitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('coordination_id')->constrained('assistance_coordinations')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->enum('state', array_column(AssistanceCoordinationState::cases(), 'value'));
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['coordination_id', 'revision'], 'coordination_transition_revision_unique');
        });
        Schema::create('assistance_coordination_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('coordination_id')->constrained('assistance_coordinations')->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->enum('sender_side', ['applicant', 'administrator']);
            $table->text('body');
            $table->dateTime('created_at');
            $table->index(['coordination_id', 'created_at', 'id'], 'coordination_messages_order_index');
        });
        Schema::table('internal_notification_events', function (Blueprint $table) {
            $table->uuid('coordination_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('internal_notification_events', fn (Blueprint $table) => $table->dropColumn('coordination_reference'));
        Schema::dropIfExists('assistance_coordination_messages');
        Schema::dropIfExists('assistance_coordination_transitions');
        Schema::dropIfExists('assistance_coordinations');
    }
};
