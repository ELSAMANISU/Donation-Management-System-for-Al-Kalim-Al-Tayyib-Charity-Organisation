<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->char('entry_key', 64)->unique();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('donor_id')->nullable()->constrained('users')->restrictOnDelete();
            if (DB::getDriverName() === 'sqlite') {
                $table->string('amount', 20);
            } else {
                $table->decimal('amount', 18, 2);
            }
            $table->char('currency', 3)->default('SDG');
            $table->boolean('anonymous')->default(false);
            $table->char('capability_hash', 64)->nullable();
            $table->enum('status', ['pending', 'succeeded', 'failed', 'cancelled', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['donor_id', 'created_at', 'id']);
            $table->index(['campaign_id', 'status']);
        });
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            // One sandbox attempt per Donation: a second successful attempt cannot exist.
            $table->foreignId('donation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 16)->default('sandbox');
            $table->uuid('provider_reference')->unique();
            $table->enum('status', ['pending', 'succeeded', 'failed', 'cancelled', 'expired'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE donations ADD CONSTRAINT donations_amount_currency_check CHECK (amount > 0 AND currency = 'SDG')");
        } else {
            DB::statement("CREATE TRIGGER donations_money_insert BEFORE INSERT ON donations WHEN NEW.currency <> 'SDG' OR CAST(NEW.amount AS NUMERIC) <= 0 BEGIN SELECT RAISE(ABORT, 'Invalid donation money'); END");
            DB::statement("CREATE TRIGGER donations_money_update BEFORE UPDATE ON donations WHEN NEW.currency <> 'SDG' OR CAST(NEW.amount AS NUMERIC) <= 0 BEGIN SELECT RAISE(ABORT, 'Invalid donation money'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('donations');
    }
};
