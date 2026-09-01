<?php

use App\Enums\HelpApplicationDuplicateWarningStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_application_duplicate_warnings', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('submitted_application_id');
            $table->foreignId('matched_application_id');
            $table->string('status', 32)->default(HelpApplicationDuplicateWarningStatus::Unreviewed->value);
            $table->foreignId('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->longText('resolution_note')->nullable();
            $table->timestamps();
            $table->unique(['submitted_application_id', 'matched_application_id'], 'help_application_duplicate_warnings_pair_unique');
            $table->index(['status', 'created_at', 'id'], 'help_application_duplicate_warnings_status_index');
            $table->index(['matched_application_id', 'id'], 'help_application_duplicate_warnings_matched_index');
            $table->index('resolved_by');
            $table->foreign('submitted_application_id', 'help_app_duplicate_warnings_submitted_fk')->references('id')->on('help_applications')->restrictOnDelete();
            $table->foreign('matched_application_id', 'help_app_duplicate_warnings_matched_fk')->references('id')->on('help_applications')->restrictOnDelete();
            $table->foreign('resolved_by', 'help_app_duplicate_warnings_resolver_fk')->references('id')->on('users')->nullOnDelete();
        });

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE help_application_duplicate_warnings ADD CONSTRAINT help_application_duplicate_warnings_not_self CHECK (submitted_application_id <> matched_application_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_application_duplicate_warnings');
    }
};
