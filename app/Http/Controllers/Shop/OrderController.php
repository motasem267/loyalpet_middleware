<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\SyncToERPNext;
use App\Models\Cart;
use App\Models\PendingSync;
use App\Models\Product;
use App\Services\ERPNextService;
use App\Services\OrderTelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * الحالات (custom_workflow_state) اللي الزبون يقدر فيها يعدّل أو يلغي الطلب — طلب
     * الحوالة بيبدأ "بانتظار تأكيد الحوالة" لحد ما الموظف يراجع الإيصال.
     */
    private const EDITABLE_STATES = ['قيد المراجعة', 'بانتظار تأكيد الحوالة'];

    private const RECEIPT_RULES = ['image', 'mimes:jpg,jpeg,png', 'max:5120'];

    // رسائل الإيصال بالعربي — التطبيق بيعرض رسالة الـ 422 للزبون زي ما هي
    private const RECEIPT_MESSAGES = [
        'transfer_receipt.required_if' => 'ارفع صورة إيصال الحوالة',
        'transfer_receipt.prohibited_unless' => 'صورة الإيصال مطلوبة فقط مع الدفع بالحوالة',
        'transfer_receipt.image' => 'صورة الإيصال لازم تكون صورة (jpg أو png)',
        'transfer_receipt.mimes' => 'صورة الإيصال لازم تكون صورة (jpg أو png)',
        'transfer_receipt.max' => 'حجم صورة الإيصال لازم يكون أقل من 5 ميغا',
        'payment_method.in' => 'طريقة الدفع غير متاحة',
    ];

    /**
     * إنشاء طلب من السلة النشطة الحالية — الكتابة دايمًا عبر الطابور (pending_syncs)،
     * مش نداء مباشر لـ ERPNext، حتى مع وجود إنترنت.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'payment_method' => ['required', 'string', Rule::in(array_keys(config('payment.methods')))],
                'transfer_receipt' => ['required_if:payment_method,bank_transfer', 'prohibited_unless:payment_method,bank_transfer', ...self::RECEIPT_RULES],
                'recipient_name' => ['required', 'string', 'max:150'],
                'recipient_phone' => ['required', 'string', 'max:20'],
                'delivery_address' => ['required', 'string', 'max:500'],
                'delivery_date' => ['required', 'date', 'after_or_equal:today'],
                'notes' => ['nullable', 'string', 'max:500'],
            ], self::RECEIPT_MESSAGES);
        } catch (ValidationException $e) {
            // التطبيق بيعرض رسالة عامة لأي 422 — من غير السطر ده ما فيش طريقة نعرف
            // أي حقل اترفض. بنسجل أسماء الحقول بس (مش قيمها) ما عدا الدفع والتاريخ.
            Log::warning('[Orders] رفض بيانات الطلب', [
                'user_id' => $request->user()->id,
                'errors' => $e->errors(),
                'fields' => array_keys($request->all()),
                'payment_method' => $request->input('payment_method'),
                'delivery_date' => $request->input('delivery_date'),
            ]);

            throw $e;
        }

        $user = $request->user();

        $cart = Cart::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        // منتج اتخفى/اتوقف بعد ما اتضاف للسلة: بيتشال، وبنوقف الطلب عشان الزبون يشوف
        // السلة والمجموع الجديد قبل ما يأكد — مش نبعت طلب ناقص من غير ما يعرف
        if ($cart && ($removed = $cart->removeUnavailableItems())) {
            throw ValidationException::withMessages([
                'cart' => ['منتجات ما عادش متاحة وتشالت من السلة: '.implode('، ', $removed).' — راجع السلة وأكمل الطلب'],
            ]);
        }

        $cart?->load('items');

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

        // الإيصال بيتحفظ هنا لحد ما SyncToERPNext يرفعه لـ ERPNext — لو ERPNext واقع
        // وقت الطلب، الصورة ما تضيعش
        $receiptPath = $request->file('transfer_receipt')?->store('transfer-receipts');

        $sync = PendingSync::create([
            'user_id' => $user->id,
            'action' => 'create',
            'doctype' => 'Sales Order',
            'payload' => [
                ...($receiptPath ? ['transfer_receipt_path' => $receiptPath] : []),
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
                    'grand_total' => round(collect($sync->payload['items'] ?? [])->sum(fn ($i) => $i['qty'] * $i['rate']), 2),
                    'transaction_date' => $sync->created_at,
                    'reference' => $sync->id,
                    'error_message' => $sync->payload['rejection_message'] ?? null,
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

    /**
     * {order} = رقم الطلب في ERPNext، أو reference (رقم) لطلب لسه في الطابور — الأخير
     * بيرجع بنفس أسماء حقول Sales Order عشان شاشة التفاصيل/التعديل في التطبيق تتعامل
     * مع الحالتين بنفس الشكل. لو الطلب اتبعت خلاص (done) بنرجّع نسخة ERPNext.
     */
    public function show(Request $request, string $order, ERPNextService $erp): JsonResponse
    {
        if (ctype_digit($order)) {
            $sync = PendingSync::where('id', (int) $order)
                ->where('user_id', $request->user()->id)
                ->where('doctype', 'Sales Order')
                ->where('status', '!=', 'cancelled')
                ->first();

            abort_if(! $sync, 404, 'الطلب غير موجود');

            if ($sync->status !== 'done' || ! $sync->erp_name) {
                return response()->json(['data' => $this->queuedOrderDetails($sync)]);
            }

            $order = $sync->erp_name;
        }

        try {
            $doc = $erp->get('Sales Order', $order);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            abort(404, 'الطلب غير موجود');
        }

        abort_unless(($doc['customer'] ?? null) === $request->user()->erp_customer_id, 403);

        $doc['items'] = $this->withProductImages($doc['items'] ?? []);
        $doc['transfer_receipt_url'] = ! empty($doc['custom_transfer_receipt'])
            ? url("/api/v1/orders/{$doc['name']}/transfer-receipt")
            : null;

        return response()->json(['data' => $doc]);
    }

    /**
     * تعديل طلب — أي حقل من حقول الإنشاء (كلها اختيارية هنا، بس لازم واحد على الأقل).
     * items لو اتبعتت بتستبدل أصناف الطلب كلها، والسعر دايمًا من الكاش المحلي (مش من التطبيق).
     * {order} = رقم الطلب في ERPNext، أو reference (رقم) لطلب لسه في الطابور.
     */
    public function update(Request $request, string $order, ERPNextService $erp, OrderTelegramNotifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['sometimes', 'string', Rule::in(array_keys(config('payment.methods')))],
            'transfer_receipt' => ['sometimes', ...self::RECEIPT_RULES],
            'recipient_name' => ['sometimes', 'string', 'max:150'],
            'recipient_phone' => ['sometimes', 'string', 'max:20'],
            'delivery_address' => ['sometimes', 'string', 'max:500'],
            'delivery_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_code' => ['required', 'string', 'distinct'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ], self::RECEIPT_MESSAGES);

        if (! $validated) {
            throw ValidationException::withMessages([
                'order' => ['لا توجد تعديلات — أرسل حقلًا واحدًا على الأقل'],
            ]);
        }

        $changes = collect($validated)->except(['items', 'transfer_receipt'])->all();
        $receipt = $request->file('transfer_receipt');

        if (isset($validated['items'])) {
            $changes['items'] = $this->buildOrderItems($validated['items']);
        }

        if (ctype_digit($order)) {
            $resend = false;
            $before = [];

            $sync = $this->withQueuedOrder($request, (int) $order, function (PendingSync $sync) use ($changes, $receipt, &$resend, &$before) {
                $before = $sync->payload;

                $this->ensureReceiptMatchesMethod($sync->payload['payment_method'] ?? null, $changes['payment_method'] ?? null, (bool) $receipt);

                $payload = array_merge($sync->payload, $changes);
                unset($payload['rejection_message']);

                if ($receipt) {
                    if (! empty($payload['transfer_receipt_path'])) {
                        Storage::delete($payload['transfer_receipt_path']);
                    }

                    $payload['transfer_receipt_path'] = $receipt->store('transfer-receipts');
                    unset($payload['transfer_receipt_url']);
                }

                // طلب رفضه ERPNext (مثلًا الرصيد ما يكفيش) وما عادش بيتعاد لوحده — بعد
                // تعديل الزبون (مثلًا غيّر للدفع عند الاستلام) بنرجعه للطابور من الأول
                $resend = $sync->status === 'failed';

                $sync->update([
                    'payload' => $payload,
                    ...($resend ? ['status' => 'pending', 'attempts' => 0, 'error' => null] : []),
                ]);
            });

            if ($resend) {
                SyncToERPNext::dispatch($sync);
            }

            $notifier->updated("مرجع #{$sync->id} (مازال ما وصلش للنظام)", $request->user(), $before, $changes, (bool) $receipt);

            return response()->json([
                'message' => 'تم تعديل الطلب',
                'reference' => $sync->id,
            ]);
        }

        $doc = $this->ensureEditableErpOrder($request, $order, $erp);

        $this->ensureReceiptMatchesMethod($doc['custom_payment_method'] ?? null, $changes['payment_method'] ?? null, (bool) $receipt);

        if ($receipt) {
            try {
                $changes['transfer_receipt'] = $erp->uploadFile($receipt->getRealPath(), $receipt->hashName());
            } catch (\Throwable $e) {
                Log::error('[Orders] فشل رفع إيصال الحوالة لـ ERPNext: '.$e->getMessage());

                throw ValidationException::withMessages([
                    'transfer_receipt' => ['تعذر رفع صورة الإيصال، حاول مرة أخرى'],
                ]);
            }
        }

        // ERPNext: الحقل اللي ما اتبعتش ما بيتغيرش، و"" بيمسحه — وLaravel بيحوّل "" لـ null
        // (ConvertEmptyStringsToNull)، فبنرجّع الملاحظات الممسوحة "" قبل الإرسال
        if (array_key_exists('notes', $changes) && $changes['notes'] === null) {
            $changes['notes'] = '';
        }

        $result = $this->callOrderMethod($erp, 'loyalpet.api.orders.update_sales_order', [
            'name' => $order,
            'customer_id' => $request->user()->erp_customer_id,
            ...$changes,
        ]);

        $notifier->updated($order, $request->user(), OrderTelegramNotifier::fromErpDoc($doc), $changes, (bool) $receipt);

        return response()->json([
            'message' => 'تم تعديل الطلب',
            'data' => $result,
        ]);
    }

    /**
     * "حذف" الطلب = إلغاؤه — طلب Sales Order معتمد في ERPNext ما بيتمسحش، بيتلغى (Cancel)
     * ويفضل مسجل للحسابات والمخزون. الطلب اللي لسه في الطابور بيتلغى محليًا وما يتبعتش.
     */
    public function destroy(Request $request, string $order, ERPNextService $erp, OrderTelegramNotifier $notifier): JsonResponse
    {
        if (ctype_digit($order)) {
            $this->withQueuedOrder($request, (int) $order, function (PendingSync $sync) {
                $sync->update(['status' => 'cancelled']);
            });

            // الطلب ده عمره ما هيوصل ERPNext، فمفيش ويبهوك حالة هيبلّغ بالإلغاء — بنبلّغ هنا.
            // إلغاء طلب في ERPNext بيوصل إشعاره من ويبهوك تغيير الحالة (WebhookController).
            $notifier->cancelled("مرجع #{$order} (مازال ما وصلش للنظام)", $request->user());

            return response()->json(['message' => 'تم إلغاء الطلب']);
        }

        $this->ensureEditableErpOrder($request, $order, $erp);

        $this->callOrderMethod($erp, 'loyalpet.api.orders.cancel_sales_order', [
            'name' => $order,
            'customer_id' => $request->user()->erp_customer_id,
        ]);

        return response()->json(['message' => 'تم إلغاء الطلب']);
    }

    /**
     * صورة إيصال الحوالة — الملف خاص (محفوظ عند لارافيل لطلب في الطابور، أو /private/files
     * في ERPNext)، فالتطبيق بيفتحه من هنا بالتوكن بتاعه بدل رابط مباشر.
     */
    public function transferReceipt(Request $request, string $order, ERPNextService $erp)
    {
        if (ctype_digit($order)) {
            $sync = PendingSync::where('id', (int) $order)
                ->where('user_id', $request->user()->id)
                ->where('doctype', 'Sales Order')
                ->first();

            abort_if(! $sync, 404, 'الطلب غير موجود');

            $path = $sync->payload['transfer_receipt_path'] ?? null;

            if ($path && Storage::exists($path)) {
                return response()->file(Storage::path($path));
            }

            abort_if(! $sync->erp_name, 404, 'لا يوجد إيصال لهذا الطلب');

            $order = $sync->erp_name;
        }

        try {
            $doc = $erp->get('Sales Order', $order);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            abort(404, 'الطلب غير موجود');
        }

        abort_unless(($doc['customer'] ?? null) === $request->user()->erp_customer_id, 403);
        abort_if(empty($doc['custom_transfer_receipt']), 404, 'لا يوجد إيصال لهذا الطلب');

        $file = $erp->downloadFile($doc['custom_transfer_receipt']);

        return response($file['body'], 200, ['Content-Type' => $file['content_type']]);
    }

    /**
     * الحوالة لازم يكون معاها إيصال: التحويل لـ bank_transfer من طريقة تانية لازم يرفع
     * إيصال، والإيصال ما ينفعش يتبعت مع طريقة غير الحوالة.
     */
    private function ensureReceiptMatchesMethod(?string $current, ?string $new, bool $hasReceipt): void
    {
        $final = $new ?? $current;

        if ($final === 'bank_transfer' && $current !== 'bank_transfer' && ! $hasReceipt) {
            throw ValidationException::withMessages([
                'transfer_receipt' => ['ارفع صورة إيصال الحوالة'],
            ]);
        }

        if ($hasReceipt && $final !== 'bank_transfer') {
            throw ValidationException::withMessages([
                'transfer_receipt' => ['صورة الإيصال مطلوبة فقط مع الدفع بالحوالة'],
            ]);
        }
    }

    private function queuedOrderDetails(PendingSync $sync): array
    {
        $payload = $sync->payload;

        $names = Product::whereIn('item_code', array_column($payload['items'] ?? [], 'item_code'))
            ->pluck('item_name', 'item_code');

        $items = array_map(fn (array $item) => [
            'item_code' => $item['item_code'],
            'item_name' => $names[$item['item_code']] ?? $item['item_code'],
            'qty' => $item['qty'],
            'rate' => $item['rate'],
            'amount' => round($item['qty'] * $item['rate'], 2),
        ], $payload['items'] ?? []);

        return [
            'name' => null,
            'reference' => $sync->id,
            'status' => $sync->status === 'failed' ? 'فشل الإرسال' : 'جاري الإرسال',
            'error_message' => $payload['rejection_message'] ?? null,
            'custom_workflow_state' => null,
            'transaction_date' => $sync->created_at,
            'delivery_date' => $payload['delivery_date'] ?? null,
            'custom_recipient_name' => $payload['recipient_name'] ?? null,
            'custom_recipient_phone' => $payload['recipient_phone'] ?? null,
            'custom_delivery_address' => $payload['delivery_address'] ?? null,
            'custom_notes' => $payload['notes'] ?? null,
            'custom_payment_method' => $payload['payment_method'] ?? null,
            'transfer_receipt_url' => ! empty($payload['transfer_receipt_path'])
                ? url("/api/v1/orders/{$sync->id}/transfer-receipt")
                : null,
            'grand_total' => round(array_sum(array_column($items, 'amount')), 2),
            'items' => $this->withProductImages($items),
        ];
    }

    /**
     * نفس شروط الإضافة للسلة (CartController::store) — المنتج نشط وظاهر في التطبيق،
     * ومش Template عنده Variants (لازم يتحدد الحجم/النكهة).
     */
    private function buildOrderItems(array $items): array
    {
        $products = Product::visibleInApp()
            ->whereIn('item_code', array_column($items, 'item_code'))
            ->withExists('variants')
            ->get()
            ->keyBy('item_code');

        return array_map(function (array $item) use ($products) {
            $product = $products->get($item['item_code']);

            if (! $product || $product->variants_exists) {
                throw ValidationException::withMessages([
                    'items' => ["المنتج {$item['item_code']} غير موجود أو غير متاح حاليًا"],
                ]);
            }

            return [
                'item_code' => $product->item_code,
                'qty' => (float) $item['qty'],
                'rate' => (float) $product->price,
            ];
        }, $items);
    }

    /**
     * قفل صف الطابور (lockForUpdate) بيمنع SyncToERPNext إنه يحجز الطلب في نفس اللحظة —
     * يا التعديل/الإلغاء يخلص الأول، يا الطلب يكون اتبعت فعلًا ونرفض.
     */
    private function withQueuedOrder(Request $request, int $reference, callable $callback): PendingSync
    {
        return DB::transaction(function () use ($request, $reference, $callback) {
            $sync = PendingSync::where('id', $reference)
                ->where('user_id', $request->user()->id)
                ->where('doctype', 'Sales Order')
                ->lockForUpdate()
                ->first();

            abort_if(! $sync, 404, 'الطلب غير موجود');

            if (! in_array($sync->status, ['pending', 'failed'], true)) {
                throw ValidationException::withMessages([
                    'order' => [$sync->status === 'cancelled'
                        ? 'الطلب ملغي بالفعل'
                        : 'الطلب وصل للنظام بالفعل — حدّث قائمة الطلبات وحاول مرة أخرى'],
                ]);
            }

            $callback($sync);

            return $sync;
        });
    }

    /**
     * فحص مبدئي هنا (ملكية + الحالة) عشان رسالة واضحة بسرعة — بس ERPNext هو اللي
     * لازم يفرض نفس الشرط فعليًا، لأن الحالة ممكن تتغير بين الفحص ده والنداء.
     */
    private function ensureEditableErpOrder(Request $request, string $order, ERPNextService $erp): array
    {
        try {
            $doc = $erp->get('Sales Order', $order);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            abort(404, 'الطلب غير موجود');
        }

        abort_unless(($doc['customer'] ?? null) === $request->user()->erp_customer_id, 403);

        if (! in_array($doc['custom_workflow_state'] ?? null, self::EDITABLE_STATES, true)) {
            throw ValidationException::withMessages([
                'order' => ['لا يمكن تعديل أو إلغاء الطلب بعد بدء تجهيزه'],
            ]);
        }

        return $doc;
    }

    /**
     * أخطاء التحقق من ERPNext (frappe.throw — مثلًا "الرصيد غير كافٍ") بنرجّعها 422
     * برسالتها عشان التطبيق يعرضها، بدل 500 عام.
     */
    private function callOrderMethod(ERPNextService $erp, string $method, array $params): array
    {
        try {
            $result = $erp->callMethod($method, $params);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $status = $e->response->status();

            // frappe.throw بيرجع 417 (أو 4xx تانية) — دي رسائل للزبون؛ 5xx بس هو عطل فعلي
            if ($status >= 500) {
                Log::error("[Orders] {$method} فشل: ".$e->getMessage());

                throw ValidationException::withMessages([
                    'order' => ['تعذر تنفيذ العملية حاليًا، حاول مرة أخرى'],
                ]);
            }

            throw ValidationException::withMessages([
                'order' => [ERPNextService::errorMessage($e->response->json() ?? []) ?? 'تعذر تنفيذ العملية'],
            ]);
        }

        $data = $result['message'] ?? $result;

        return is_array($data) ? $data : [];
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
