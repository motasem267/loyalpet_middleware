<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\SyncToERPNext;
use App\Models\Cart;
use App\Models\PendingSync;
use App\Models\Product;
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
     * Pagination عبر ?page= و ?per_page= — الطلبات المعلّقة (لسه ما وصلتش ERPNext) تظهر
     * في أول صفحة بس. offset حقيقي من ERPNext (مش تقريبي)، لكن من غير عدّ كلي (has_more
     * heuristic) عشان نتجنب نداء إضافي لـ ERPNext بس لحساب العدد.
     */
    public function index(Request $request, ERPNextService $erp): JsonResponse
    {
        $user = $request->user();
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $pending = collect();

        if ($page === 1) {
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
        }

        // custom_workflow_state (مش workflow_state المدمج في Frappe) — نفس الحقل
        // المستخدم في WebhookController::handleSalesOrderEvent.
        $synced = $user->erp_customer_id
            ? $erp->getList('Sales Order',
                filters: [['customer', '=', $user->erp_customer_id]],
                fields: ['name', 'status', 'custom_workflow_state', 'grand_total', 'transaction_date', 'custom_payment_method'],
                limit: $perPage,
                offset: ($page - 1) * $perPage,
            )
            : [];

        return response()->json([
            'data' => $pending->concat($synced)->values(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => count($synced) >= $perPage,
            ],
        ]);
    }

    public function show(Request $request, string $order, ERPNextService $erp): JsonResponse
    {
        try {
            $doc = $erp->get('Sales Order', $order);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            abort(404, 'الطلب غير موجود');
        }

        abort_unless(($doc['customer'] ?? null) === $request->user()->erp_customer_id, 403);

        $doc['items'] = $this->withProductImages($doc['items'] ?? []);

        return response()->json(['data' => $doc]);
    }

    /**
     * صورة المنتج مش راجعة أصلًا في صفوف items بتاعة Sales Order في ERPNext —
     * بنجيبها من الكاش المحلي (products) بمطابقة item_code، مش من ERPNext تاني.
     */
    private function withProductImages(array $items): array
    {
        $images = Product::whereIn('item_code', array_column($items, 'item_code'))
            ->get(['item_code', 'image_path'])
            ->keyBy('item_code');

        return array_map(function (array $item) use ($images) {
            $item['image_url'] = $images->get($item['item_code'])?->image_url;

            return $item;
        }, $items);
    }
}
