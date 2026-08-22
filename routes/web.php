<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Webhooks\LencoWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

/*
 * The payment provider calling us back. Unauthenticated by necessity — the provider
 * has no session — and trusted only on the strength of its signature, which the
 * controller checks against the raw body before anything is written down.
 */
Route::post('webhooks/lenco', LencoWebhookController::class)->name('webhooks.lenco');

require __DIR__.'/app.php';
require __DIR__.'/my.php';
require __DIR__.'/settings.php';
