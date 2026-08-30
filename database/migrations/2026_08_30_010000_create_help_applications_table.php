<?php

use App\Enums\HelpApplicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('applicant_id');
            $table->foreignId('category_id')->nullable();
            $table->string('status', 32)->default(HelpApplicationStatus::Draft->value);
            $table->boolean('open_slot')->nullable()->default(true);
            $table->longText('full_name')->nullable();
            $table->longText('email')->nullable();
            $table->longText('phone')->nullable();
            $table->longText('address')->nullable();
            $table->text('date_of_birth')->nullable();
            $table->string('identity_document_type', 32)->nullable();
            $table->char('identity_issuing_country', 2)->nullable();
            $table->longText('identity_document_number')->nullable();
            $table->char('identity_blind_index', 64)->nullable();
            $table->unsignedSmallInteger('identity_blind_index_version')->nullable();
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                // SQLite's NUMERIC affinity cannot retain DECIMAL(18,2) values
                // at the supported maximum without binary-float rounding.
                $table->string('requested_amount', 20)->nullable();
            } else {
                $table->decimal('requested_amount', 18, 2)->nullable();
            }
            $table->longText('private_story')->nullable();
            $table->longText('preferred_receiving_method')->nullable();
            $table->string('public_identity_preference', 32)->nullable();
            $table->string('consent_version', 50)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->foreignId('category_assigned_by')->nullable();
            $table->timestamp('category_assigned_at')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('appeal_eligibility_ended_at')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'open_slot'], 'help_applications_applicant_open_unique');
            $table->index(['applicant_id', 'status'], 'help_applications_applicant_status_index');
            $table->index(['category_id', 'status'], 'help_applications_category_status_index');
            $table->index(['status', 'submitted_at', 'id'], 'help_applications_review_order_index');
            $table->index('identity_blind_index');
            $table->index('category_assigned_by');
            $table->index('reviewed_by');
            $table->index('decided_by');
            $table->index('updated_by');

            $table->foreign('applicant_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('category_assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_applications');
    }
};
