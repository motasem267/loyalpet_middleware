<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cart_id', 'erp_item_code', 'quantity', 'price_at_add'])]
class CartItem extends Model
{
    protected $appends = ['line_total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'price_at_add' => 'decimal:2',
        ];
    }

    protected function lineTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => number_format(((float) $this->quantity) * ((float) $this->price_at_add), 2, '.', ''),
        );
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'erp_item_code', 'item_code');
    }
}
