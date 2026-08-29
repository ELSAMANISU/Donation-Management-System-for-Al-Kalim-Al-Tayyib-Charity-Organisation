<?php

use App\Enums\CampaignStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id');
            $table->string('slug', 160)->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('summary_ar');
            $table->text('summary_en');
            $table->longText('story_ar');
            $table->longText('story_en');
            $table->decimal('target_amount', 18, 2);
            $table->decimal('raised_amount', 18, 2)->default(0);
            $table->string('status', 32)->default(CampaignStatus::Draft->value)->index();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_urgent')->default(false);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('image_path', 2048)->nullable();
            $table->string('image_alt_ar')->nullable();
            $table->string('image_alt_en')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('paused_at')->nullable();
            $table->text('pause_reason')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('aid_delivery_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->longText('impact_update_ar')->nullable();
            $table->longText('impact_update_en')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
            $table->index('updated_by');
            $table->index(
                ['category_id', 'status', 'published_at'],
                'campaigns_category_publication_index'
            );
            $table->index(
                ['status', 'is_featured', 'is_urgent', 'priority'],
                'campaigns_homepage_priority_index'
            );

            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
