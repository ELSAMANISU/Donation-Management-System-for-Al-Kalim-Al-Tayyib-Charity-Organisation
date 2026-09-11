<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('help_application_id')->nullable()->unique()->constrained('help_applications')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropForeign(['help_application_id']));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropUnique(['help_application_id']));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('help_application_id'));
    }
};
