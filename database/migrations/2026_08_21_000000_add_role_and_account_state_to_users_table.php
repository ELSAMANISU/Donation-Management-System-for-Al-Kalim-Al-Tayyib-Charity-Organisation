<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(UserRole::User->value)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();
            $table->foreignId('disabled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['disabled_by']);
            $table->dropColumn([
                'role',
                'is_active',
                'disabled_at',
                'disabled_reason',
                'disabled_by',
            ]);
        });
    }
};
