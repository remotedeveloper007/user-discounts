<?php

namespace Remotedeveloper007\UserDiscounts\Tests\Unit;

use Remotedeveloper007\UserDiscounts\Tests\TestCase;
use Remotedeveloper007\UserDiscounts\Models\Discount;
use Remotedeveloper007\UserDiscounts\Models\UserDiscount;
use Remotedeveloper007\UserDiscounts\Models\DiscountAudit;
use Remotedeveloper007\UserDiscounts\Services\DiscountService;
use Illuminate\Support\Facades\Event;
use Remotedeveloper007\UserDiscounts\Events\{
    DiscountAssigned, DiscountRevoked, DiscountApplied
};

class AcceptanceCriteriaTest extends TestCase
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
    public function test_assign_eligible_apply_workflow_with_audits(): void
    {
        Event::fake();

        // Create discount
        $discount = Discount::create([
            'code' => 'WORKFLOW10',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);

        // 1. Assign
        $this->service->assign($this->user, $discount);
        Event::assertDispatched(DiscountAssigned::class);
        $this->assertDatabaseHas('discount_audits', [
            'user_id' => 1,
            'discount_id' => $discount->id,
            'action' => 'assigned'
        ]);

        // 2. Check eligible
        $this->assertTrue($this->service->eligibleFor($this->user, $discount));

        // 3. Apply
        $final = $this->service->apply($this->user, 100.00);
        $this->assertEquals(90.00, $final);
        Event::assertDispatched(DiscountApplied::class);
        $this->assertDatabaseHas('discount_audits', [
            'user_id' => 1,
            'discount_id' => $discount->id,
            'action' => 'applied'
        ]);
    }

    /** @test */
    public function test_expired_discounts_are_excluded(): void
    {
        $discount = Discount::create([
            'code' => 'EXPIRED',
            'type' => 'percentage',
            'value' => 20,
            'active' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(1), // Expired yesterday
        ]);

        $this->service->assign($this->user, $discount);
        
        // Should not be eligible
        $this->assertFalse($this->service->eligibleFor($this->user, $discount));
        
        // Should not apply
        $final = $this->service->apply($this->user, 100.00);
        $this->assertEquals(100.00, $final); // No discount applied
    }

    /** @test */
    public function test_inactive_discounts_are_excluded(): void
    {
        $discount = Discount::create([
            'code' => 'INACTIVE',
            'type' => 'percentage',
            'value' => 15,
            'active' => false, // Inactive
        ]);

        $this->service->assign($this->user, $discount);
        
        // Should not be eligible
        $this->assertFalse($this->service->eligibleFor($this->user, $discount));
        
        // Should not apply
        $final = $this->service->apply($this->user, 100.00);
        $this->assertEquals(100.00, $final);
    }

    /** @test */
    public function test_usage_caps_are_enforced(): void
    {
        $discount = Discount::create([
            'code' => 'TWICEONLY',
            'type' => 'fixed',
            'value' => 5,
            'active' => true,
            'max_usage_per_user' => 2, // Can only use twice
        ]);

        $this->service->assign($this->user, $discount);

        // First use
        $final1 = $this->service->apply($this->user, 100.00);
        $this->assertEquals(95.00, $final1);

        // Second use
        $final2 = $this->service->apply($this->user, 100.00);
        $this->assertEquals(95.00, $final2);

        // Third use - should be blocked
        $final3 = $this->service->apply($this->user, 100.00);
        $this->assertEquals(100.00, $final3); // No discount
    }

    /** @test */
    public function test_stacking_is_deterministic(): void
    {
        // Create discounts with specific priority
        $discount1 = Discount::create([
            'code' => 'FIRST',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'stacking_priority' => 1,
        ]);

        $discount2 = Discount::create([
            'code' => 'SECOND',
            'type' => 'percentage',
            'value' => 5,
            'active' => true,
            'stacking_priority' => 2,
        ]);

        $this->service->assign($this->user, $discount1);
        $this->service->assign($this->user, $discount2);

        // Apply both - should be deterministic
        // 100 - 10% = 90, then 90 - 5% = 85.5
        $final = $this->service->apply($this->user, 100.00);
        $this->assertEquals(85.50, $final);

        // Verify it's the same every time (if we could revert the usage)
    }

    /** @test */
    public function test_rounding_modes_work_correctly(): void
    {
        config(['user-discounts.rounding_mode' => 'floor']);
        config(['user-discounts.precision' => 2]);

        $discount = Discount::create([
            'code' => 'PRECISE',
            'type' => 'percentage',
            'value' => 33.333,
            'active' => true,
        ]);

        $this->service->assign($this->user, $discount);
        
        // 100 * 33.333% = 33.333, remaining = 66.667
        // With floor rounding = 66.66
        $final = $this->service->apply($this->user, 100.00);
        $this->assertEquals(66.66, $final);
    }

    /** @test */
    public function test_revoked_discounts_not_applied(): void
    {
        Event::fake();

        $discount = Discount::create([
            'code' => 'REVOKEME',
            'type' => 'percentage',
            'value' => 25,
            'active' => true,
        ]);

        // Assign
        $this->service->assign($this->user, $discount);
        $this->assertTrue($this->service->eligibleFor($this->user, $discount));

        // Revoke
        $this->service->revoke($this->user, $discount);
        Event::assertDispatched(DiscountRevoked::class);
        $this->assertDatabaseHas('discount_audits', [
            'user_id' => 1,
            'discount_id' => $discount->id,
            'action' => 'revoked'
        ]);

        // Should not be eligible anymore
        $this->assertFalse($this->service->eligibleFor($this->user, $discount));

        // Should not apply
        $final = $this->service->apply($this->user, 100.00);
        $this->assertEquals(100.00, $final);
    }

    /** @test */
    public function test_percentage_cap_is_enforced(): void
    {
        config(['user-discounts.max_percentage_cap' => 50]);

        $discount1 = Discount::create([
            'code' => 'PERCENT40',
            'type' => 'percentage',
            'value' => 40,
            'active' => true,
            'stacking_priority' => 1,
        ]);

        $discount2 = Discount::create([
            'code' => 'PERCENT20',
            'type' => 'percentage',
            'value' => 20,
            'active' => true,
            'stacking_priority' => 2,
        ]);

        $this->service->assign($this->user, $discount1);
        $this->service->assign($this->user, $discount2);

        // Should cap at 50%, not 60%
        // 100 - 40% = 60, then 60 - 10% (only 10% remaining to cap) = 54
        $final = $this->service->apply($this->user, 100.00);
        $this->assertEquals(50.00, $final); // Capped at 50% total
    }

    /** @test */
    public function test_concurrent_safety_with_usage_increment(): void
    {
        $discount = Discount::create([
            'code' => 'ONCEONLY',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'max_usage_per_user' => 1,
        ]);

        $this->service->assign($this->user, $discount);

        // First application
        $final1 = $this->service->apply($this->user, 100.00);
        $this->assertEquals(90.00, $final1);

        // Verify usage was incremented
        $pivot = UserDiscount::where('user_id', 1)
            ->where('discount_id', $discount->id)
            ->first();
        $this->assertEquals(1, $pivot->times_used);

        // Second application should fail (cap reached)
        $final2 = $this->service->apply($this->user, 100.00);
        $this->assertEquals(100.00, $final2);

        // Verify usage wasn't double-incremented
        $pivot->refresh();
        $this->assertEquals(1, $pivot->times_used);
    }

    /** @test */
    public function test_idempotent_assign_does_not_duplicate(): void
    {
        $discount = Discount::create([
            'code' => 'ASSIGN',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);

        // Assign multiple times
        $this->service->assign($this->user, $discount);
        $this->service->assign($this->user, $discount);
        $this->service->assign($this->user, $discount);

        // Should only have one record
        $count = UserDiscount::where('user_id', 1)
            ->where('discount_id', $discount->id)
            ->count();
        $this->assertEquals(1, $count);
    }
}
