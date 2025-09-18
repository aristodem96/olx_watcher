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
use Illuminate\Support\Facades\Log;

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
        Log::info('Start CheckListingPrice handle job');
        $lock = Cache::lock("listing:{$this->listingId}:lock", 120);
        if (!$lock->get()) {
            $this->release(5);
            return;
        }

        try {
            $listing = Listing::find($this->listingId);
            if (!$listing || $listing->status !== 'active') return;

            $data = $fetcher->fetch(
                $listing->url,
                $listing->etag,
                optional($listing->last_modified)?->toDateTimeString()
            );

            Log::info('********FETCHER DATA: ' . json_encode($data));

            if (!empty($data['not_modified'])) {
                $listing->update([
                    'last_checked_at' => now(),
                    'next_check_at'   => now()->addSeconds($listing->check_interval_sec),
                ]);
                return;
            }

            $old = $listing->last_price ?? null;
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

                NotifySubscribers::dispatch($listing->id, $old, $new, $cur)->onQueue('notifications');
            } else {
                $listing->save();
            }
        }
        catch (\Exception $e) {
            Log::info($e);
        }
        finally {
            optional($lock)->release();
        }
    }
}
