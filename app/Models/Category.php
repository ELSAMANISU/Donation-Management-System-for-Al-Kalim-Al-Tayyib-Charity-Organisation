<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Only public category content is safe for ordinary mass assignment.
     * Administrative state, ordering, actor IDs, and deletion state must be
     * assigned explicitly by an authorized service or controller.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name_ar',
        'name_en',
        'slug',
        'description_ar',
        'description_en',
        'icon',
        'image_path',
    ];

    /**
     * Normalize an explicitly supplied slug. A future Form Request should
     * validate this normalized value before an authorized service persists it.
     */
    public static function normalizeSlug(string $slug): string
    {
        return Str::slug(Str::lower($slug), '-', 'en');
    }

    public static function isManagedImagePath(?string $path): bool
    {
        return is_string($path)
            && preg_match('/\Acategories\/[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z/', $path) === 1;
    }

    public function publicImageUrl(): ?string
    {
        return self::isManagedImagePath($this->image_path)
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }

    /**
     * @return Attribute<string, string>
     */
    protected function slug(): Attribute
    {
        return Attribute::set(fn (string $value): string => self::normalizeSlug($value));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return HasMany<HelpApplication, $this> */
    public function helpApplications(): HasMany
    {
        return $this->hasMany(HelpApplication::class);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeInDisplayOrder(Builder $query): void
    {
        $query
            ->orderBy('display_order')
            ->orderBy('name_en')
            ->orderBy('id');
    }
}
