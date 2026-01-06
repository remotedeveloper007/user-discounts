<?php
namespace Remotedeveloper007\UserDiscounts\Events;

class DiscountAssigned
{
    public function __construct(
        public object $user,
        public object $discount
    ) {}
}
