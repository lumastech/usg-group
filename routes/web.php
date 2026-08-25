<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Webhooks\LencoWebhookController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Unity is a closed group, so there is no public landing page. The root URL hands
 * guests straight to the login form and forwards anyone already signed in to their
 * portal. Fortify's logout and account-deletion responses land here, which is why
 * the name has to stay `home`.
 */
Route::get('/', fn () => Auth::check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

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
