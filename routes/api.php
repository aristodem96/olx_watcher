<?php

use App\Http\Controllers\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/subscriptions', [SubscriptionController::class, 'store']);
Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show']);

Route::get('/subscriptions/{subscription}/verify', [SubscriptionController::class, 'verify'])
    ->middleware('signed')
    ->name('subscriptions.verify');

Route::post('/subscriptions/{subscription}/resend-verification', [SubscriptionController::class, 'resend'])
    ->name('subscriptions.resend');
