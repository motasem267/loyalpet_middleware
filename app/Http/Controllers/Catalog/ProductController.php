<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * منتجات مرتبطة مباشرة بتصنيف معيّن (item_group)، أو كل المنتجات النشطة لو ما تحددش تصنيف.
     * يدعم البحث بالاسم عبر ?search=. Pagination عبر ?page= و ?per_page=.
     */
    public function index(Request $request): JsonResponse
    {
        // variant_of != null معناه المنتج ده "عبوة/حجم" تابع لمنتج تاني (Item Variants) —
        // بيظهر جوّه variants بتاع المنتج الأساسي بس، مش كسطر مستقل في القائمة الرئيسية.
        $query = Product::query()->where('is_active', true)->where('show_in_app', true)->whereNull('variant_of');

        if ($itemGroup = $request->query('item_group')) {
            $query->where('item_group_erp_name', $itemGroup);
        }

        if ($search = $request->query('search')) {
            $query->where('item_name', 'like', '%'.$search.'%');
        }

        return response()->json($this->paginate($request, $query->orderBy('id')));
    }

    /**
     * المنتجات المميّزة (custom_is_featured في ERPNext) — مرتبة بحقل الترتيب
     * المخصص (custom_featured_order). Pagination عبر ?page= و ?per_page=.
     */
    public function featured(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('is_active', true)
            ->where('show_in_app', true)
            ->where('is_featured', true)
            ->whereNull('variant_of')
            ->orderBy('featured_order')
            ->orderBy('id');

        return response()->json($this->paginate($request, $query));
    }

    /**
     * تفاصيل منتج واحد (وصف + صورة) — لشاشة تفاصيل المنتج. لو المنتج عنده عبوات/أحجام
     * تانية (نظام Item Variants في ERPNext — variant_of)، بترجع في مفتاح "variants".
     */
    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active && $product->show_in_app, 404, 'المنتج غير موجود');

        $variants = $product->variants()
            ->where('is_active', true)
            ->where('show_in_app', true)
            ->get(['id', 'erp_name', 'item_code', 'item_name', 'price', 'image_path', 'attributes']);

        return response()->json([
            'data' => [
                ...$product->toArray(),
                'variants' => $variants,
            ],
        ]);
    }

    private function paginate(Request $request, Builder $query): array
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }
}
