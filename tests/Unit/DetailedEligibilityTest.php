<?php

namespace Remotedeveloper007\UserDiscounts\Tests\Unit;

use Remotedeveloper007\UserDiscounts\Tests\TestCase;
use Remotedeveloper007\UserDiscounts\Models\Discount;
use Remotedeveloper007\UserDiscounts\Models\UserDiscount;
use Remotedeveloper007\UserDiscounts\Services\DiscountService;

/**
 * Tests for detailed eligibility and application methods
 * These methods return structured data for use by consuming applications
 */
class DetailedEligibilityTest extends TestCase
{
    protected DiscountService $service;
    protected object $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        
        $this->service = new DiscountService();
        $this->user = (object) ['id' => 1];
    }

    /** @test */
    public function test_eligibility_with_reason_returns_expired_status(): void
    {
        $discount = Discount::create([
            'code' => 'EXPIRED10',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'ends_at' => now()->subDays(1),
        ]);

        $this->service->assign($this->user, $discount);
        
        $result = $this->service->eligibleForWithReason($this->user, $discount);
        
        $this->assertFalse($result['eligible']);
        $this->assertEquals('expired', $result['reason']);
    }

    /** @test */
    public function test_eligibility_with_reason_returns_inactive_status(): void
    {
        $discount = Discount::create([
            'code' => 'INACTIVE10',
            'type' => 'percentage',
            'value' => 10,
            'active' => false,
        ]);

        $this->service->assign($this->user, $discount);
        
        $result = $this->service->eligibleForWithReason($this->user, $discount);
        
        $this->assertFalse($result['eligible']);
        $this->assertEquals('inactive', $result['reason']);
    }

    /** @test */
    public function test_eligibility_with_reason_returns_revoked_status(): void
    {
        $discount = Discount::create([
            'code' => 'REVOKED10',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);

        $this->service->assign($this->user, $discount);
        $this->service->revoke($this->user, $discount);
        
        $result = $this->service->eligibleForWithReason($this->user, $discount);
        
        $this->assertFalse($result['eligible']);
        $this->assertEquals('revoked', $result['reason']);
    }

    /** @test */
    public function test_eligibility_with_reason_returns_usage_cap_reached(): void
    {
        $discount = Discount::create([
            'code' => 'ONCEONLY',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'max_usage_per_user' => 1,
        ]);

        $this->service->assign($this->user, $discount);
        $this->service->apply($this->user, 100.00);
        
        $result = $this->service->eligibleForWithReason($this->user, $discount);
        
        $this->assertFalse($result['eligible']);
        $this->assertEquals('usage_cap_reached', $result['reason']);
    }

    /** @test */
    public function test_apply_with_details_returns_structured_data(): void
    {
        $discount = Discount::create([
            'code' => 'TEST10',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);

        $this->service->assign($this->user, $discount);
        $result = $this->service->applyWithDetails($this->user, 100.00);

        $this->assertArrayHasKey('final_amount', $result);
        $this->assertArrayHasKey('applied', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertEquals(90.00, $result['final_amount']);
        $this->assertCount(1, $result['applied']);
        $this->assertEquals('TEST10', $result['applied'][0]['code']);
    }

    /** @test */
    public function test_apply_with_details_shows_skipped_inactive_discounts(): void
    {
        $activeDiscount = Discount::create([
            'code' => 'ACTIVE10',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'stacking_priority' => 1,
        ]);

        $inactiveDiscount = Discount::create([
            'code' => 'INACTIVE20',
            'type' => 'percentage',
            'value' => 20,
            'active' => false,
            'stacking_priority' => 2,
        ]);

        $this->service->assign($this->user, $activeDiscount);
        $this->service->assign($this->user, $inactiveDiscount);

        $result = $this->service->applyWithDetails($this->user, 100.00);

        $this->assertEquals(90.00, $result['final_amount']);
        $this->assertCount(1, $result['applied']);
        $this->assertCount(1, $result['skipped']);
        $this->assertEquals('INACTIVE20', $result['skipped'][0]['code']);
        $this->assertEquals('inactive', $result['skipped'][0]['reason']);
    }

    /** @test */
    public function test_apply_with_details_enforces_usage_cap(): void
    {
        $discount = Discount::create([
            'code' => 'TWICEONLY',
            'type' => 'fixed',
            'value' => 5,
            'active' => true,
            'max_usage_per_user' => 2,
        ]);

        $this->service->assign($this->user, $discount);

        // First two applications succeed
        $result1 = $this->service->applyWithDetails($this->user, 100.00);
        $this->assertCount(1, $result1['applied']);
        
        $result2 = $this->service->applyWithDetails($this->user, 100.00);
        $this->assertCount(1, $result2['applied']);

        // Third is skipped
        $result3 = $this->service->applyWithDetails($this->user, 100.00);
        $this->assertEquals(100.00, $result3['final_amount']);
        $this->assertCount(0, $result3['applied']);
        $this->assertCount(1, $result3['skipped']);
        $this->assertEquals('usage_cap_reached', $result3['skipped'][0]['reason']);
    }
}
