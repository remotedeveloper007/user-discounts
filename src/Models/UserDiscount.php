<?php

namespace Remotedeveloper007\UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;

class UserDiscount extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id','discount_id','times_used',
        'assigned_at','revoked_at'
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
