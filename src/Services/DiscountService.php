<?php

namespace Remotedeveloper007\UserDiscounts\Services;

use Illuminate\Support\Facades\DB;
use Remotedeveloper007\UserDiscounts\Models\Discount;
use Remotedeveloper007\UserDiscounts\Models\UserDiscount;
use Remotedeveloper007\UserDiscounts\Models\DiscountAudit;
use Remotedeveloper007\UserDiscounts\Events\{
    DiscountAssigned, DiscountRevoked, DiscountApplied
};

class DiscountService
{
    public function assign(object $user, Discount $discount): void
    {
        UserDiscount::firstOrCreate(
            ['user_id' => $user->id, 'discount_id' => $discount->id],
            ['assigned_at' => now()]
        );

        $this->audit($user->id, $discount->id, 'assigned');
        event(new DiscountAssigned($user, $discount));
    }

    public function revoke(object $user, Discount $discount): void
    {
        UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->update(['revoked_at' => now()]);

        $this->audit($user->id, $discount->id, 'revoked');
        event(new DiscountRevoked($user, $discount));
    }

    public function eligibleFor(object $user, Discount $discount): bool
    {
        $pivot = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        if (!$discount->active) return false;
        if ($discount->starts_at && now()->lt($discount->starts_at)) return false;
        if ($discount->ends_at && now()->gt($discount->ends_at)) return false;
        if (!$pivot || $pivot->revoked_at) return false;

        return $discount->max_usage_per_user === null
            || $pivot->times_used < $discount->max_usage_per_user;
    }

    public function apply(object $user, float $amount): float
    {
        return DB::transaction(function () use ($user, $amount) {
            $total = $amount;
            $totalPercentageDiscount = 0;
            $maxCap = config('user-discounts.max_percentage_cap', 100);
            $precision = config('user-discounts.precision', 2);
            $roundingMode = config('user-discounts.rounding_mode', 'round');

            // Get discounts with proper locking and deterministic ordering
            $discounts = Discount::join(
                'user_discounts','discounts.id','=','user_discounts.discount_id'
            )
            ->where('user_discounts.user_id', $user->id)
            ->whereNull('user_discounts.revoked_at')
            ->orderBy('discounts.stacking_priority')
            ->orderBy('discounts.id') // Deterministic secondary sort
            ->lockForUpdate()
            ->get(['discounts.*']);

            foreach ($discounts as $discount) {
                // Lock the pivot row to prevent race conditions
                $pivot = UserDiscount::where('user_id', $user->id)
                    ->where('discount_id', $discount->id)
                    ->lockForUpdate()
                    ->first();

                // Re-check eligibility within locked context
                if (!$this->eligibleForLocked($discount, $pivot)) {
                    continue;
                }

                // Calculate discount with percentage cap enforcement
                if ($discount->type === 'percentage') {
                    // Check if adding this discount would exceed the cap
                    if ($totalPercentageDiscount + $discount->value > $maxCap) {
                        $remainingPercentage = $maxCap - $totalPercentageDiscount;
                        if ($remainingPercentage <= 0) {
                            continue; // Skip if cap already reached
                        }
                        // Apply remaining percentage to ORIGINAL amount
                        $delta = $amount * ($remainingPercentage / 100);
                        $totalPercentageDiscount = $maxCap;
                    } else {
                        // Apply percentage to current total (sequential stacking)
                        $delta = $total * ($discount->value / 100);
                        $totalPercentageDiscount += $discount->value;
                    }
                } else {
                    $delta = $discount->value;
                }

                $total -= $delta;

                // Increment usage count (already locked)
                $pivot->increment('times_used');

                // Audit the application
                $this->audit($user->id, $discount->id, 'applied', [
                    'discounted' => $delta,
                    'original_amount' => $amount,
                    'remaining' => $total
                ]);

                event(new DiscountApplied($user, $discount, [
                    'discounted' => $delta,
                ]));
            }

            // Apply configured rounding
            return $this->applyRounding($total, $precision, $roundingMode);
        });
    }

    /**
     * Check eligibility within a locked transaction context
     */
    protected function eligibleForLocked(Discount $discount, ?UserDiscount $pivot): bool
    {
        if (!$discount->active) return false;
        if ($discount->starts_at && now()->lt($discount->starts_at)) return false;
        if ($discount->ends_at && now()->gt($discount->ends_at)) return false;
        if (!$pivot || $pivot->revoked_at) return false;

        return $discount->max_usage_per_user === null
            || $pivot->times_used < $discount->max_usage_per_user;
    }

    /**
     * Apply configured rounding mode
     */
    protected function applyRounding(float $value, int $precision, string $mode): float
    {
        $multiplier = pow(10, $precision);
        
        return match($mode) {
            'floor' => floor($value * $multiplier) / $multiplier,
            'ceil' => ceil($value * $multiplier) / $multiplier,
            default => round($value, $precision),
        };
    }

    /**
     * Create audit record
     */
    protected function audit(int $userId, int $discountId, string $action, array $metadata = []): void
    {
        DiscountAudit::create([
            'user_id' => $userId,
            'discount_id' => $discountId,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now()
        ]);
    }
}
