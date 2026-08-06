<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['erp_name', 'zone_name', 'delivery_price', 'is_active'])]
class DeliveryZone extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'delivery_price' => 'decimal:2',
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
