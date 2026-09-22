<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', fn (Blueprint $table) => $table->index(['status', 'paid_at', 'id'], 'donations_reporting_index'));
    }

    public function down(): void
    {
        Schema::table('donations', fn (Blueprint $table) => $table->dropIndex('donations_reporting_index'));
    }
};
