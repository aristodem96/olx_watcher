<?php

namespace App\Jobs;

use App\Mail\PriceChangedMail;
use App\Models\Listing;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class NotifySubscribers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $listingId, public ?int $oldPrice, public int $newPrice) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $listing = Listing::find($this->listingId);
        if (!$listing) return;

        $emails = Subscription::where('listing_id', $listing->id)
            ->whereNotNull('email_verified_at')
            ->pluck('email');

        foreach ($emails as $email) {
            Mail::to($email)->queue(new PriceChangedMail($listing, $this->oldPrice, $this->newPrice));
        }
    }
}
