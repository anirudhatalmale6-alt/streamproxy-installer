<?php

use App\Http\Controllers\StreamController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Stream proxy routes — session/cookie middleware explicitly removed
|--------------------------------------------------------------------------
| These routes are in the web group but strip the expensive session and
| cookie middleware. Session I/O on disk adds 100-300ms per .ts segment
| request. Stream routes don't need auth or CSRF protection.
|--------------------------------------------------------------------------
*/
Route::withoutMiddleware([
    StartSession::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
])->group(function () {
    Route::get('/view/{payload}.m3u', [StreamController::class, 'stream'])
        ->where('payload', '[A-Za-z0-9\-_]+')
        ->name('stream.m3u');

    Route::get('/view/{payload}', [StreamController::class, 'stream'])
        ->where('payload', '[A-Za-z0-9\-_]+')
        ->name('stream');
});
