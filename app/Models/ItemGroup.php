<?php

namespace App\Models;

use App\Models\Concerns\HasErpImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['erp_name', 'parent_erp_name', 'is_group', 'image_path', 'is_active'])]
class ItemGroup extends Model
{
    use HasErpImage;

    public $timestamps = false;

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_erp_name', 'erp_name');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_erp_name', 'erp_name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'item_group_erp_name', 'erp_name');
    }
}
