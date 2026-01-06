<?php

namespace Remotedeveloper007\UserDiscounts\Tests\Unit;

use Remotedeveloper007\UserDiscounts\Tests\TestCase;
use Remotedeveloper007\UserDiscounts\Models\Discount;
use Remotedeveloper007\UserDiscounts\Services\DiscountService;
use Illuminate\Support\Facades\Schema;

class DiscountUsageCapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_usage_cap_is_enforced(): void
    {
        $user = (object) ['id' => 1];

        $discount = Discount::create([
            'code' => 'ONCE10',
            'type' => 'percentage',
            'value' => 10,
            'max_usage_per_user' => 1,
        ]);

        $service = new DiscountService();
        $service->assign($user, $discount);

        $this->assertEquals(90.00, $service->apply($user, 100.00));
        $this->assertEquals(100.00, $service->apply($user, 100.00));
    }
}
