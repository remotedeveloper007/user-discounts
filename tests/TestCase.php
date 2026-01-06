<?php

namespace Remotedeveloper007\UserDiscounts\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            \Remotedeveloper007\UserDiscounts\UserDiscountsServiceProvider::class,
        ];
    }
}
