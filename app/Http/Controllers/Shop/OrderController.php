<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\SyncToERPNext;
use App\Models\Cart;
use App\Models\PendingSync;
use App\Services\ERPNextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * إنشاء طلب من السلة النشطة الحالية — الكتابة دايمًا عبر الطابور (pending_syncs)،
     * مش نداء مباشر لـ ERPNext، حتى مع وجود إنترنت.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:wallet,cash_on_delivery'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        $cart = Cart::where('user_id', $user->id)
            ->where('status', 'active')
            ->with('items')
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['السلة فارغة — أضف منتجات أولًا'],
            ]);
        }

        $items = $cart->items->map(fn ($item) => [
            'item_code' => $item->erp_item_code,
            'qty' => (float) $item->quantity,
            'rate' => (float) $item->price_at_add,
        ])->values()->all();

        $sync = PendingSync::create([
            'user_id' => $user->id,
            'action' => 'create',
            'doctype' => 'Sales Order',
            'payload' => [
                'items' => $items,
                'payment_method' => $validated['payment_method'],
                'recipient_name' => $validated['recipient_name'],
                'recipient_phone' => $validated['recipient_phone'],
                'delivery_address' => $validated['delivery_address'],
                'delivery_date' => $validated['delivery_date'],
                'notes' => $validated['notes'] ?? null,
            ],
            'status' => 'pending',
            'priority' => 2,
        ]);

        $cart->update(['status' => 'converted']);

        SyncToERPNext::dispatch($sync);

        return response()->json([
            'message' => 'تم استلام طلبك وجاري إرساله',
            'reference' => $sync->id,
        ], 202);
    }

    /**
     * قراءة مباشرة من ERPNext (بدون كاش) + أي طلبات لسه في طور الإرسال (مش موجودة في ERPNext بعد).
     */
    public function index(Request $request, ERPNextService $erp): JsonResponse
    {
        $user = $request->user();

        $pending = PendingSync::where('user_id', $user->id)
            ->where('doctype', 'Sales Order')
            ->whereIn('status', ['pending', 'processing', 'failed'])
            ->get()
            ->map(fn (PendingSync $sync) => [
                'name' => null,
                'status' => $sync->status === 'failed' ? 'فشل الإرسال' : 'جاري الإرسال',
                'grand_total' => null,
                'transaction_date' => $sync->created_at,
                'reference' => $sync->id,
            ]);

        $synced = $user->erp_customer_id
            ? $erp->getList('Sales Order',
                filters: [['customer', '=', $user->erp_customer_id]],
                fields: ['name', 'status', 'workflow_state', 'grand_total', 'transaction_date', 'custom_payment_method'],
                limit: 50,
            )
            : [];

        return response()->json(['data' => $pending->concat($synced)->values()]);
    }

    public function show(Request $request, string $order, ERPNextService $erp): JsonResponse
    {
        try {
            $doc = $erp->get('Sales Order', $order);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            abort(404, 'الطلب غير موجود');
        }

        abort_unless(($doc['customer'] ?? null) === $request->user()->erp_customer_id, 403);

        return response()->json(['data' => $doc]);
    }
}
