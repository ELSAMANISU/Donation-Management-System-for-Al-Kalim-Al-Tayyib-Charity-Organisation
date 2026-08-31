<?php

use App\Enums\HelpApplicationDocumentSecurityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_application_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('help_application_id');
            // Canonical paths are 100 characters. 191 is safely unique-indexable
            // on MySQL/MariaDB installations using utf8mb4.
            $table->string('storage_path', 191)->unique();
            $table->longText('original_name');
            $table->string('extension', 3);
            $table->string('mime_type', 32);
            $table->unsignedBigInteger('size_bytes');
            $table->longText('checksum');
            $table->string('checksum_algorithm', 16)->default('sha256');
            $table->string('purpose', 32)->nullable();
            $table->string('uploader_kind', 16);
            $table->foreignId('uploaded_by')->nullable();
            $table->string('security_status', 32)->default(HelpApplicationDocumentSecurityStatus::Pending->value);
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable();
            $table->timestamps();

            $table->index(['help_application_id', 'removed_at', 'security_status', 'id'], 'help_application_documents_active_security_index');
            $table->index(['help_application_id', 'created_at', 'id'], 'help_application_documents_upload_order_index');
            $table->index('uploaded_by');
            $table->index('removed_by');

            $table->foreign('help_application_id')->references('id')->on('help_applications')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('removed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_application_documents');
    }
};
