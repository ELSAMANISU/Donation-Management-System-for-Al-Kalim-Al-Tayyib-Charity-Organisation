<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Only privacy-safe public copy and image metadata may be mass assigned.
     * Financial, lifecycle, ownership, and deletion state must be assigned by
     * a future authorized service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title_ar',
        'title_en',
        'summary_ar',
        'summary_en',
        'story_ar',
        'story_en',
        'image_path',
        'image_alt_ar',
        'image_alt_en',
        'impact_update_ar',
        'impact_update_en',
    ];

    public static function normalizeSlug(string $slug): string
    {
        return Str::slug(Str::lower($slug), '-', 'en');
    }

    public static function isManagedImagePath(mixed $path, int $campaignId): bool
    {
        return is_string($path)
            && preg_match('/\Acampaigns\/'.preg_quote((string) $campaignId, '/').'\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:jpg|png|webp)\z/', $path) === 1;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return Attribute<string, string> */
    protected function slug(): Attribute
    {
        return Attribute::set(fn (string $value): string => self::normalizeSlug($value));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'raised_amount' => 'decimal:2',
            'status' => CampaignStatus::class,
            'is_featured' => 'boolean',
            'is_urgent' => 'boolean',
            'priority' => 'integer',
            'expires_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'funded_at' => 'immutable_datetime',
            'aid_delivery_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @param Builder<Campaign> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CampaignStatus::Active);
    }

    /** @param Builder<Campaign> $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /** @param Builder<Campaign> $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query
            ->published()
            ->whereIn('status', [
                CampaignStatus::Active,
                CampaignStatus::Funded,
                CampaignStatus::AidDelivery,
                CampaignStatus::Completed,
            ]);
    }

    /** @param Builder<Campaign> $query */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /** @param Builder<Campaign> $query */
    public function scopeUrgent(Builder $query): void
    {
        $query->where('is_urgent', true);
    }

    /** @param Builder<Campaign> $query */
    public function scopeInPriorityOrder(Builder $query): void
    {
        $query
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->orderBy('id');
    }
}
