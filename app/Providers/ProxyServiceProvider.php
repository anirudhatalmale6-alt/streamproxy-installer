<?php

namespace App\Providers;

use App\Services\LinkCrypt;
use Illuminate\Support\ServiceProvider;

class ProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton so the key/IV config is only read once per request
        $this->app->singleton(LinkCrypt::class);
    }
}
