<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('slug', 160)->unique();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('image_path', 2048)->nullable();
            $table->unsignedInteger('display_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['is_active', 'display_order', 'name_en', 'id'],
                'categories_active_display_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
