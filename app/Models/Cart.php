<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'status'])]
class Cart extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * منتج اتضاف للسلة وبعدين اتخفى (show_in_app = 0) أو اتوقف في ERPNext — بيتشال
     * من السلة قبل العرض وقبل تحويلها لطلب، عشان ما يتطلبش وهو مش متاح.
     *
     * @return string[] أسماء المنتجات اللي اتشالت
     */
    public function removeUnavailableItems(): array
    {
        $codes = $this->items()->pluck('erp_item_code');

        $available = Product::visibleInApp()->whereIn('item_code', $codes)->pluck('item_code');
        $unavailable = $codes->diff($available);

        if ($unavailable->isEmpty()) {
            return [];
        }

        $names = Product::whereIn('item_code', $unavailable)->pluck('item_name', 'item_code');

        $this->items()->whereIn('erp_item_code', $unavailable)->delete();

        return $unavailable->map(fn ($code) => $names[$code] ?? $code)->values()->all();
    }
}
