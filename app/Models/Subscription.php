<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'email',
        'email_verified_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function (Subscription $s) {
            $s->email = strtolower(trim($s->email));

            $verifiedAt = static::where('email', $s->email)
                ->whereNotNull('email_verified_at')
                ->orderByDesc('email_verified_at')
                ->value('email_verified_at');

            if ($verifiedAt && is_null($s->email_verified_at)) {
                $s->email_verified_at = $verifiedAt;
            }
        });
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function isVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }
}
