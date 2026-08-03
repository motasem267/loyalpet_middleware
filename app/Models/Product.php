<?php

namespace App\Models;

use App\Models\Concerns\HasErpImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'erp_name', 'item_code', 'item_name', 'item_group_erp_name',
    'description', 'image_path', 'price', 'is_active',
    'show_in_app', 'is_featured', 'featured_order',
])]
class Product extends Model
{
    use HasErpImage;

    public $timestamps = false;

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'show_in_app' => 'boolean',
            'is_featured' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function itemGroup(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_erp_name', 'erp_name');
    }
}
