<?php
namespace Remotedeveloper007\UserDiscounts\Events;

class DiscountRevoked
{
    public function __construct(
        public object $user,
        public object $discount
    ) {}
}
