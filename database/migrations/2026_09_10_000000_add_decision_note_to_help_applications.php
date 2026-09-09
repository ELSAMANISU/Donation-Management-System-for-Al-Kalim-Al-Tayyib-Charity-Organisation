<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_applications', function (Blueprint $table): void {
            $table->longText('decision_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('help_applications', function (Blueprint $table): void {
            $table->dropColumn('decision_note');
        });
    }
};
