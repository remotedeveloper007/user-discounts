<?php
namespace Remotedeveloper007\UserDiscounts\Events;

class DiscountApplied
{
    public function __construct(
        public object $user,
        public object $discount,
        public array $metadata = []
    ) {}
}
