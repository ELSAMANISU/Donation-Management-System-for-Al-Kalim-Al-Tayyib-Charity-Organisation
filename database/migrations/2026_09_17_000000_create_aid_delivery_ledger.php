<?php

use App\Enums\AidDeliveryAction;
use App\Enums\AidDeliveryState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aid_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('coordination_id')->constrained('assistance_coordinations')->restrictOnDelete();
            // SQLite NUMERIC affinity converts large decimals to floating point.
            // Operational MySQL/MariaDB uses exact DECIMAL(18,2).
            if (DB::getDriverName() === 'sqlite') {
                $table->string('amount', 20);
            } else {
                $table->decimal('amount', 18, 2);
            }
            $table->enum('currency', ['SDG']);
            $table->enum('state', array_column(AidDeliveryState::cases(), 'value'));
            $table->unsignedInteger('revision');
            $table->char('entry_key', 64)->unique();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            // SQLite and MySQL/MariaDB support indexed generated columns and multiple
            // NULLs in a unique index. The database derives the slot, never the caller.
            $table->unsignedBigInteger('unfinished_coordination_id')->nullable()
                ->storedAs("CASE WHEN state IN ('in_progress', 'problem') THEN coordination_id ELSE NULL END")->unique('aid_delivery_unfinished_unique');
            $table->index(['coordination_id', 'state', 'id']);
        });
        Schema::create('aid_delivery_transitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('delivery_id')->constrained('aid_deliveries')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->enum('state', array_column(AidDeliveryState::cases(), 'value'));
            $table->enum('action', array_column(AidDeliveryAction::cases(), 'value'));
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->dateTime('created_at');
            $table->unique(['delivery_id', 'revision']);
        });
        Schema::create('aid_delivery_proofs', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('delivery_id')->unique()->constrained('aid_deliveries')->restrictOnDelete();
            $table->char('sandbox_reference', 64)->unique();
            $table->string('generator', 64);
            $table->unsignedInteger('version');
            $table->dateTime('created_at');
        });
        Schema::table('internal_notification_events', fn (Blueprint $table) => $table->uuid('delivery_reference')->nullable());
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER aid_delivery_money_'.strtolower($operation).' BEFORE '.$operation." ON aid_deliveries WHEN CAST(NEW.amount AS NUMERIC) <= 0 OR NEW.currency <> 'SDG' BEGIN SELECT RAISE(ABORT, 'Invalid sandbox money'); END");
            }
        } else {
            DB::statement("ALTER TABLE aid_deliveries ADD CONSTRAINT aid_delivery_money_check CHECK (amount > 0 AND currency = 'SDG')");
        }
    }

    public function down(): void
    {
        Schema::table('internal_notification_events', fn (Blueprint $table) => $table->dropColumn('delivery_reference'));
        Schema::dropIfExists('aid_delivery_proofs');
        Schema::dropIfExists('aid_delivery_transitions');
        Schema::dropIfExists('aid_deliveries');
    }
};
