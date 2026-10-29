<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['guest', 'throttle:20,1'])->prefix('auth/oidc')->name('oidc.')->group(function () {
    Route::get('redirect', [OidcController::class, 'redirect'])->name('redirect');
    Route::get('callback', [OidcController::class, 'callback'])->name('callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
