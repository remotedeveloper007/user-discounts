<?php

namespace Remotedeveloper007\UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'discount_id',
        'action',
        'metadata',
        'created_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
