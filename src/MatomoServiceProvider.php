<?php

namespace Redbit\Matomo;

use Illuminate\Support\ServiceProvider;

class MatomoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Matomo::class, function () {
            return new Matomo;
        });
    }
}
