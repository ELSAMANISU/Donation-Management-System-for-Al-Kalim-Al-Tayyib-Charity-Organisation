<?php

namespace App\Models;

use App\Enums\DonationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Donation extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'entry_key', 'campaign_id', 'donor_id', 'capability_hash', 'donor', 'campaign', 'paymentAttempt', 'expires_at', 'updated_at'];

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'anonymous' => 'boolean', 'status' => DonationStatus::class,
            'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function paymentAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class);
    }
}
