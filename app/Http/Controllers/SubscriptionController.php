<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscribeRequest;
use App\Models\Listing;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function store(SubscribeRequest $request): JsonResponse
    {
        $url   = rtrim($request->input('url'), "/ \t\n\r\0\x0B");
        $email = strtolower($request->input('email'));

        $listing = Listing::firstOrCreate(
            ['url' => $url],
            ['next_check_at' => now()]
        );

        $subscription = Subscription::firstOrCreate([
            'listing_id' => $listing->id,
            'email'      => $email,
        ]);

//        CheckListingPrice::dispatch($listing->id);

        return response()->json([
            'subscription_id' => $subscription->id,
            'listing_id'      => $listing->id,
            'email'           => $subscription->email,
            'message'         => 'Subscribed successfully.',
        ], 202);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load('listing');

        return response()->json([
            'id'              => $subscription->id,
            'email'           => $subscription->email,
            'email_verified'  => (bool) $subscription->email_verified_at,
            'listing' => [
                'id'              => $subscription->listing->id,
                'url'             => $subscription->listing->url,
                'last_price'      => $subscription->listing->last_price,
                'currency'        => $subscription->listing->currency,
                'last_checked_at' => $subscription->listing->last_checked_at,
            ],
        ]);
    }
}
