<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * منتجات مرتبطة مباشرة بتصنيف معيّن (item_group)، أو كل المنتجات النشطة لو ما تحددش تصنيف.
     * يدعم البحث بالاسم عبر ?search=.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->where('is_active', true);

        if ($itemGroup = $request->query('item_group')) {
            $query->where('item_group_erp_name', $itemGroup);
        }

        if ($search = $request->query('search')) {
            $query->where('item_name', 'like', '%'.$search.'%');
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * تفاصيل منتج واحد (وصف + صورة) — لشاشة تفاصيل المنتج.
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product]);
    }
}
