<?php

namespace Remotedeveloper007\UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = [
        'code','type','value','active',
        'stacking_priority','max_usage_per_user',
        'starts_at','ends_at'
    ];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
