<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Do not force the root URL here. Livewire signed upload URLs and
        // Laravel signed URLs in general must be generated and validated
        // against the request's actual host. Asset URLs are handled via
        // ASSET_URL in .env.
    }
}
