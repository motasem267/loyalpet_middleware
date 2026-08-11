<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with('product')
            ->latest('created_at')
            ->get()
            ->map(fn (Favorite $favorite) => [
                'id' => $favorite->product->id,
                'erp_name' => $favorite->product->erp_name,
                'item_code' => $favorite->product->item_code,
                'item_name' => $favorite->product->item_name,
                'price' => $favorite->product->price,
                'image_url' => $favorite->product->image_url,
                'favorited_at' => $favorite->created_at,
            ])
            ->values();

        return response()->json(['data' => $favorites]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        Favorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $validated['product_id'],
        ]);

        return response()->json(['message' => 'تمت الإضافة للمفضلة'], 201);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        Favorite::where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json(['message' => 'تم الحذف من المفضلة']);
    }
}
