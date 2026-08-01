<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\BundleItem;
use Illuminate\Http\JsonResponse;

class BundleController extends Controller
{
    /**
     * الباقات النشطة — تُستخدم في بانر الرئيسية (بدل العروض/الكوبونات).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Bundle::where('is_active', true)->get(),
        ]);
    }

    /**
     * تفاصيل باقة واحدة + محتوياتها (المنتجات اللي جواها) — لشاشة تفاصيل الباقة
     * لما المستخدم يدوس على باقة من القائمة.
     */
    public function show(Bundle $bundle): JsonResponse
    {
        $bundle->load('items.product');

        return response()->json([
            'data' => [
                ...$bundle->toArray(),
                'items' => $bundle->items->map(fn (BundleItem $item) => [
                    'item_code' => $item->item_code,
                    'item_name' => $item->product?->item_name,
                    'image_url' => $item->product?->image_url,
                    'price' => $item->product?->price,
                    'qty' => $item->qty,
                ]),
            ],
        ]);
    }
}
