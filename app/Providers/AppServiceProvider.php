<?php

namespace App\Providers;

use App\Sync\Clients\DentolizeClient;
use App\Sync\Clients\FakeDentolizeClient;
use App\Sync\Clients\FakeQoyodClient;
use App\Sync\Clients\QoyodClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(QoyodClient::class, FakeQoyodClient::class);
        $this->app->bind(DentolizeClient::class, FakeDentolizeClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
