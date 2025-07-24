<?php

use Lyre\Billing\Http\Controllers;
use Illuminate\Support\Facades\Route;

Route::apiResources([
    'subscriptions' => Controllers\SubscriptionController::class,
    'subscriptionplans' => Controllers\SubscriptionPlanController::class,
]);

Route::get('/subscriptionplans/{plan}/subscribe/', [Controllers\SubscriptionPlanController::class, 'subscribe']);
Route::get('/subscriptions/{subscription}/approved/', [Controllers\SubscriptionController::class, 'approved']);

Route::post('billing/webhook', Controllers\WebhookController::class)->name('webhook');
