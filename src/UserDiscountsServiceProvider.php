<?php

namespace Remotedeveloper007\UserDiscounts;

use Illuminate\Support\ServiceProvider;

class UserDiscountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/user-discounts.php',
            'user-discounts'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/user-discounts.php' =>
                config_path('user-discounts.php'),
        ], 'user-discounts-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' =>
                database_path('migrations'),
        ], 'user-discounts-migrations');
    }
}
