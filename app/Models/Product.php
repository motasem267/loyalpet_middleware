<?php

namespace App\Models;

use App\Models\Concerns\HasErpImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'erp_name', 'item_code', 'item_name', 'item_group_erp_name', 'variant_of', 'attributes',
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
            'attributes' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public function itemGroup(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_erp_name', 'erp_name');
    }

    /**
     * عبوات/أحجام هذا المنتج (نظام Item Variants في ERPNext) — بيُستخدم على منتج
     * "قالب" (Template، variant_of = null) لجيب المنتجات (Variants) التابعة له.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'variant_of', 'erp_name');
    }

    /**
     * القالب (Template) اللي الـ Variant ده تابع ليه.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'variant_of', 'erp_name');
    }

    /**
     * المصدر الوحيد لـ "المنتج ده يظهر/يتباع في التطبيق": نشط + show_in_app، ولو Variant
     * فالقالب بتاعه كمان نشط وظاهر — قالب مخفي بيخفي كل أوزانه/نكهاته معاه.
     */
    #[Scope]
    protected function visibleInApp(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('show_in_app', true)
            ->where(fn (Builder $q) => $q->whereNull('variant_of')
                ->orWhereHas('template', fn (Builder $t) => $t->where('is_active', true)->where('show_in_app', true)));
    }

    /**
     * اللي يظهر في القوائم/البحث/تفاصيل المنتج: قالب (مش Variant) ظاهر، ولو عنده Variants
     * لازم واحد منهم على الأقل ظاهر — قالب كل أوزانه مخفية ما ينفعش يتشرى، فما يظهرش.
     */
    #[Scope]
    protected function listable(Builder $query): void
    {
        $query->visibleInApp()
            ->whereNull('variant_of')
            ->where(fn (Builder $q) => $q->whereDoesntHave('variants')
                ->orWhereHas('variants', fn (Builder $v) => $v->where('is_active', true)->where('show_in_app', true)));
    }

    /**
     * اللي التطبيق يقدر يفتحه بـ GET /products/{id}: قالب يظهر في القوائم، أو Variant
     * ظاهر (هو والقالب). نفس الشرط للمفضلة وروابط الإشعارات.
     */
    #[Scope]
    protected function openableInApp(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $t) => $t->listable())
            ->orWhere(fn (Builder $v) => $v->whereNotNull('variant_of')->visibleInApp()));
    }

    public function isVisibleInApp(): bool
    {
        return static::visibleInApp()->whereKey($this->getKey())->exists();
    }
}
