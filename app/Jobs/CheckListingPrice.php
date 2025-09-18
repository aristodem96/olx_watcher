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

    public $tries   = 3;
    public $backoff = [10, 30, 60];

    public function __construct(public int $listingId) {}

    public function handle(PriceFetcher $fetcher): void
    {
        $lock = Cache::lock("listing:{$this->listingId}:lock", 120);
        if (!$lock->get()) {
            $this->release(5);
            return;
        }

        try {
            $listing = Listing::find($this->listingId);
            if (!$listing || $listing->status !== 'active') return;

            $cacheKey = "listing:{$listing->id}:last_fetch";
            $cached   = Cache::get($cacheKey);

            if ($cached !== null) {
                $old = $listing->last_price;
                $new = $cached['price'] ?? null;
                $cur = $cached['currency'] ?? $listing->currency;
                Log::info('***CACHE:***');
                Log::info('New price: ' . $new);
                Log::info('OLD price: ' . $old);

                $listing->last_checked_at = now();
                $listing->next_check_at   = now()->addSeconds($listing->check_interval_sec);

                if ($new !== null && $new !== $old) {
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

                return;
            }

            $data = $fetcher->fetch(
                $listing->url,
                $listing->etag,
                $listing->last_modified?->toRfc7231String()
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

            if ($new !== null && $new !== $old) {
                $listing->last_price = $new;
                $listing->currency   = $cur;
                $listing->save();

                PriceHistory::create([
                    'listing_id' => $listing->id,
                    'price'      => $new,
                    'currency'   => $cur,
                    'seen_at'    => now(),
                ]);

                Cache::put($cacheKey, ['price' => $new, 'currency' => $cur], now()->addMinutes(30));

                NotifySubscribers::dispatch($listing->id, $old, $new, $cur)->onQueue('notifications');
            } else {
                $listing->save();
                if ($new !== null) {
                    Cache::put($cacheKey, ['price' => $new, 'currency' => $cur], now()->addMinutes(30));
                }
            }
        } catch (\Throwable $e) {
            Log::error('CheckListingPrice error', ['listingId' => $this->listingId, 'err' => $e->getMessage()]);
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }
}
