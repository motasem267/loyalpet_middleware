<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'erp_name', 'title', 'discount_type', 'discount_value',
    'coupon_code', 'valid_from', 'valid_upto', 'is_active',
])]
class OfferCache extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'valid_from' => 'date',
            'valid_upto' => 'date',
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
