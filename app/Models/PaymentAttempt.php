<?php

namespace App\Models;

use App\Enums\DonationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'donation_id', 'provider', 'provider_reference', 'donation', 'created_at', 'updated_at'];

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    protected function casts(): array
    {
        return ['status' => DonationStatus::class, 'completed_at' => 'immutable_datetime'];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }
}
