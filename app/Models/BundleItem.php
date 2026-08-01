<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bundle_id', 'item_code', 'qty'])]
class BundleItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /**
     * مربوط بـ item_code مش FK حقيقي — نفس منطق ربط المنتجات بباقي الجداول في المشروع.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_code', 'item_code');
    }
}
