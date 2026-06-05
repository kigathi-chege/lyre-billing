<?php

use Lyre\Billing\Http\Controllers;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class])
    ->group(function () {
        Route::apiResources([
            'subscriptions' => Controllers\SubscriptionController::class,
            'subscriptionplans' => Controllers\SubscriptionPlanController::class,
            'paymentmethods' => Controllers\PaymentMethodController::class,
        ]);

        Route::get('/subscriptionplans/{plan}/subscribe/', [Controllers\SubscriptionPlanController::class, 'subscribe']);
        Route::prefix('billing/subscriptions')->group(function () {
            Route::get('/provider-return', [Controllers\SubscriptionController::class, 'providerReturned']);
            Route::get('/provider-return-redirect', [Controllers\SubscriptionController::class, 'providerReturnRedirect']);
            Route::get('/provider-cancel-redirect', [Controllers\SubscriptionController::class, 'providerCancelRedirect']);
        });

        Route::post('billing/webhook', Controllers\WebhookController::class)->name('billing.webhook');
    });
