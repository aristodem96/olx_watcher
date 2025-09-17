<?php

namespace App\Console\Commands;

use App\Jobs\CheckListingPrice;
use App\Models\Listing;
use Illuminate\Console\Command;

class EnqueueDueListings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'listings:enqueue-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find ads whose time is up for review and add the job to the queue.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Listing::where(function ($q) {
            $q->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', now());
        })
            ->where('status', 'active')
            ->orderBy('next_check_at')
            ->limit(200)
            ->get()
            ->each(fn ($l) => CheckListingPrice::dispatch($l->id))->onQueue('price-checks');

        $this->info('Enqueued due listings');
        return self::SUCCESS;
    }
}
