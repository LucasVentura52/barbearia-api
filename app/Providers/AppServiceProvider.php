<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Relation::morphMap([
            'service' => Service::class,
            'product' => Product::class,
            'staff' => User::class,
            'user' => User::class,
        ]);
    }
}
