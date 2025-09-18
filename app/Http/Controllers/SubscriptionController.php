<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscribeRequest;
use App\Jobs\CheckListingPrice;
use App\Mail\VerifySubscriptionMail;
use App\Models\Listing;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

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

        Log::info('Email is verified: ' . ($subscription->isVerified() ? 'true' : 'false'));

        if (!$subscription->isVerified()) {
            $verifyUrl = URL::temporarySignedRoute(
                'subscriptions.verify',
                now()->addDay(),
                ['subscription' => $subscription->id]
            );

            Mail::to($subscription->email)->queue(
                (new VerifySubscriptionMail($subscription, $verifyUrl))->onQueue('notifications')
            );
        }

        CheckListingPrice::dispatch($listing->id)->onQueue('price-checks');

        return response()->json([
            'subscription_id' => $subscription->id,
            'listing_id'      => $listing->id,
            'email'           => $subscription->email,
            'message'         => 'Subscribed successfully.',
        ], 202);
    }

    public function verify(Subscription $subscription): JsonResponse
    {
        if ($subscription->email_verified_at) {
            return response()->json(['message' => 'Email already confirmed.'], 200);
        }

        $subscription->email_verified_at = now();
        $subscription->save();

        return response()->json(['message' => 'Email confirmed. Thank you!'], 200);
    }

    public function resend(Subscription $subscription): JsonResponse
    {
        if ($subscription->email_verified_at) {
            return response()->json(['message' => 'Email already confirmed.'], 200);
        }

        $verifyUrl = URL::temporarySignedRoute(
            'subscriptions.verify',
            now()->addDay(),
            ['subscription' => $subscription->id]
        );

        Mail::to($subscription->email)->queue(
            (new VerifySubscriptionMail($subscription, $verifyUrl))->onQueue('notifications')
        );

        return response()->json(['message' => 'Confirmation letter sent again.'], 200);
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
                'next_check_at'   => $subscription->listing->next_check_at,
            ],
        ]);
    }
}
