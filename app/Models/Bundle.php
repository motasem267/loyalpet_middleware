<?php

namespace App\Models;

use App\Models\Concerns\HasErpImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['erp_name', 'item_code', 'title', 'description', 'price', 'image_path', 'is_active'])]
class Bundle extends Model
{
    use HasErpImage;

    public $timestamps = false;

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }
}
