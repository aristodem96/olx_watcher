<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Listing extends Model
{
    use HasFactory;

    protected $fillable = [
        'url',
        'last_price',
        'currency',
        'last_checked_at',
        'next_check_at',
        'status',
        'check_interval_sec',
        'etag',
        'last_modified'
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'next_check_at'   => 'datetime',
        'last_modified' => 'datetime',
    ];

    /** Связи */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceHistories()
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function scopeDue($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', now());
        })->where('status', 'active');
    }

    public function scheduleNextCheck(?int $seconds = null): void
    {
        $this->next_check_at = now()->addSeconds($seconds ?? $this->check_interval_sec ?? 900);
        $this->save();
    }
}
