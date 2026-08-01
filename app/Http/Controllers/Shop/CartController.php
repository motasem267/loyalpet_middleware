<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cart = Cart::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->with('items.product')
            ->first();

        return response()->json(['data' => $this->transformCart($cart)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_code' => ['required', 'string'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $product = Product::where('item_code', $validated['item_code'])
            ->where('is_active', true)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'item_code' => ['المنتج غير موجود أو غير متاح حاليًا'],
            ]);
        }

        $cart = Cart::firstOrCreate([
            'user_id' => $request->user()->id,
            'status' => 'active',
        ]);

        $quantity = $validated['quantity'] ?? 1;
        $item = $cart->items()->where('erp_item_code', $validated['item_code'])->first();

        if ($item) {
            $item->increment('quantity', $quantity);
        } else {
            $cart->items()->create([
                'erp_item_code' => $validated['item_code'],
                'quantity' => $quantity,
                'price_at_add' => $product->price,
            ]);
        }

        $cart->load('items.product');

        return response()->json(['data' => $this->transformCart($cart)], 201);
    }

    public function update(Request $request, CartItem $cartItem): JsonResponse
    {
        $this->authorizeOwnership($request, $cartItem);

        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $cartItem->update(['quantity' => $validated['quantity']]);

        $cart = $cartItem->cart->load('items.product');

        return response()->json(['data' => $this->transformCart($cart)]);
    }

    public function destroy(Request $request, CartItem $cartItem): JsonResponse
    {
        $this->authorizeOwnership($request, $cartItem);

        $cart = $cartItem->cart;
        $cartItem->delete();

        $cart->load('items.product');

        return response()->json(['data' => $this->transformCart($cart)]);
    }

    private function authorizeOwnership(Request $request, CartItem $cartItem): void
    {
        abort_unless($cartItem->cart->user_id === $request->user()->id, 403);
    }

    private function transformCart(?Cart $cart): array
    {
        if (! $cart) {
            return ['items' => [], 'total' => '0.00'];
        }

        $items = $cart->items->map(fn (CartItem $item) => [
            'id' => $item->id,
            'item_code' => $item->erp_item_code,
            'item_name' => $item->product?->item_name,
            'image_url' => $item->product?->image_url,
            'quantity' => $item->quantity,
            'price_at_add' => $item->price_at_add,
            'line_total' => $item->line_total,
        ]);

        return [
            'items' => $items,
            'total' => number_format($items->sum(fn ($i) => (float) $i['line_total']), 2, '.', ''),
        ];
    }
}
