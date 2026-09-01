<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_applications', function (Blueprint $table) {
            $table->index(['identity_blind_index_version', 'identity_blind_index', 'id'], 'help_applications_identity_version_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('help_applications', function (Blueprint $table) {
            $table->dropIndex('help_applications_identity_version_lookup_index');
        });
    }
};
