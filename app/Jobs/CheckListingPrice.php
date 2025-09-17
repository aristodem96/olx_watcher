<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\PriceHistory;
use App\Services\PriceFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CheckListingPrice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */

    public $tries   = 3;
    public $backoff = [10, 30, 60];

    public function __construct(public int $listingId) {}

    /**
     * Execute the job.
     */
    public function handle(PriceFetcher $fetcher): void
    {
        $lock = Cache::lock("listing:{$this->listingId}:lock", 120);
        if (!$lock->get()) return;

        try {
            $listing = Listing::find($this->listingId);
            if (!$listing || $listing->status !== 'active') return;

            $data = $fetcher->fetch(
                $listing->url,
                $listing->etag,
                optional($listing->last_modified)?->toDateTimeString()
            );

            if (!empty($data['not_modified'])) {
                $listing->update([
                    'last_checked_at' => now(),
                    'next_check_at'   => now()->addSeconds($listing->check_interval_sec),
                ]);
                return;
            }

            $old = $listing->last_price;
            $new = $data['price'] ?? null;
            $cur = $data['currency'] ?? $listing->currency;

            $listing->last_checked_at = now();
            $listing->etag            = $data['etag'] ?? $listing->etag;
            $listing->last_modified   = $data['last_modified'] ?? $listing->last_modified;
            $listing->next_check_at   = now()->addSeconds($listing->check_interval_sec);

            if ($new && $new !== $old) {
                $listing->last_price = $new;
                $listing->currency   = $cur;
                $listing->save();

                PriceHistory::create([
                    'listing_id' => $listing->id,
                    'price'      => $new,
                    'currency'   => $cur,
                    'seen_at'    => now(),
                ]);

                NotifySubscribers::dispatch($listing->id, $old, $new)->onQueue('emails');
            } else {
                $listing->save();
            }
        } finally {
            optional($lock)->release();
        }
    }
}
