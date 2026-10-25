<?php

namespace App\Http\Controllers;

use App\Jobs\SendPushNotification;
use App\Models\AuditLog;
use App\Models\Bundle;
use App\Models\DeliveryZone;
use App\Models\DeviceToken;
use App\Models\ItemGroup;
use App\Models\OrderStatusHistory;
use App\Models\PendingSync;
use App\Models\Product;
use App\Models\User;
use App\Services\ERPNextService;
use App\Services\FirebaseService;
use App\Services\OrderTelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    /**
     * نقطة استقبال عامة لكل Webhooks اللي ERPNext بيبعتها (آلية الـ Webhook DocType
     * المدمجة في Frappe) — تحقّق من التوقيع الأول قبل أي حاجة.
     */
    public function handleErp(Request $request, ERPNextService $erp): JsonResponse
    {
        $this->verifySignature($request);

        // Frappe's native Webhook framework ما بيبعتش Content-Type: application/json،
        // فـ $request->all() ما بيتعرفش على الـ body كـ JSON — بنفكّه من الـ raw body مباشرة.
        $data = json_decode($request->getContent(), true) ?? [];
        $doctype = $data['doctype'] ?? null;

        // زر "إرسال" في شاشة App Notification — محتاج رد فيه عدد الوصول/الفشل، مش {"status":"ok"}
        if ($doctype === 'App Notification') {
            return $this->processAppNotification($data);
        }

        // إعادة التسمية (Rename) في ERPNext بتغيّر الـ name نفسه، والويبهوك العادي بيوصل بالاسم
        // الجديد بس — فكان بيتخلق سجل جديد ويفضل القديم والمنتجات مربوطة بيه. الحدث ده
        // بيتبعت من hook الـ after_rename في تطبيق loyalpet ومعاه الاسمين.
        if (($data['event'] ?? null) === 'renamed' || isset($data['old_name'], $data['new_name'])) {
            // warning مش info — LOG_LEVEL في البرودكشن warning، ومن غيره ما فيش أثر نتتبع بيه
            Log::warning('[Webhook:erp] rename', $data);

            match ($doctype) {
                'Item Group' => $this->handleItemGroupRename($data, $erp),
                'Item' => $this->handleItemRename($data, $erp),
                default => Log::warning('[Webhook:erp] rename غير معالج لـ '.($doctype ?? 'null')),
            };

            return response()->json(['status' => 'ok']);
        }

        match ($doctype) {
            'Customer' => $this->handleCustomerEvent($data),
            'Sales Order' => $this->handleSalesOrderEvent($data),
            'Item' => $this->handleItemUpdate($data, $erp),
            'Item Group' => $this->handleItemGroupUpdate($data),
            'Product Bundle' => $this->handleProductBundleUpdate($data, $erp),
            'Delivery Zone' => $this->handleDeliveryZoneUpdate($data),
            'Wallet Transaction', 'Vet Appointment', 'Hotel Booking' => $this->logAuditEvent($doctype, $data),
            default => Log::info('[Webhook:erp] doctype غير معالج: '.($doctype ?? 'null')),
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * لو الـ Customer مربوط بمستخدم موبايل (custom_app_user_id)، نأكّد إن حالة الـ sync
     * بتاعه "synced" ومعاه رقم الـ Customer في ERPNext. ده تحديث احتياطي — الطريق الأساسي
     * (SyncCustomerToERPNext) بيحدّث نفس الحقول مباشرة وقت الإنشاء، فده defense in depth
     * لو الـ Customer اتعمل أو اتعدّل من غير الطريق ده.
     */
    /**
     * ⚠️ أسماء الحقول هنا (erp_customer_id, name, phone, address) مطابقة لـ Webhook Data
     * مخصص اتبنى في ERPNext — مش أسماء حقول Customer الخام في Frappe (اللي فيها
     * "name" = رقم الدوك و"mobile_no" = الهاتف). لو اتغيّر الـ mapping هناك، لازم يتغيّر هنا.
     */
    private function handleCustomerEvent(array $data): void
    {
        $erpCustomerId = $data['erp_customer_id'] ?? null;
        $userId = $data['custom_app_user_id'] ?? null;

        if (! $erpCustomerId) {
            Log::warning('[Webhook:erp] Customer event بدون erp_customer_id: '.json_encode($data));

            return;
        }

        if (! $userId) {
            $this->createUserFromErpCustomer($data, $erpCustomerId);

            return;
        }

        $user = User::find($userId);

        if (! $user) {
            Log::info('[Webhook:erp] مفيش User مطابق للـ id: '.$userId);

            return;
        }

        $user->update([
            'erp_customer_id' => $erpCustomerId,
            'erp_sync_status' => 'synced',
            'erp_synced_at' => now(),
        ]);
    }

    /**
     * Customer اتضاف مباشرة من ERPNext (مش عبر التطبيق) — بننشئله حساب فعّال فورًا
     * بكلمة مرور عشوائية غير معروفة؛ يقدر يستخدم /auth/forgot-password (رمز SMS
     * الموجود أصلًا) عشان يحدد كلمة مروره الحقيقية ويسجّل دخوله.
     */
    private function createUserFromErpCustomer(array $data, string $erpCustomerId): void
    {
        if (User::where('erp_customer_id', $erpCustomerId)->exists()) {
            return;
        }

        $phone = $data['phone'] ?? null;

        if (! $phone) {
            Log::warning('[Webhook:erp] Customer بدون phone، ما نقدرش ننشئ حساب: '.$erpCustomerId);

            return;
        }

        if (User::where('phone', $phone)->exists()) {
            Log::warning('[Webhook:erp] رقم الهاتف '.$phone.' مستخدم مسبقًا عند مستخدم آخر، تعارض عند ربط Customer: '.$erpCustomerId);

            return;
        }

        User::create([
            'name' => $data['name'] ?? $erpCustomerId,
            'phone' => $phone,
            'address' => $data['address'] ?? null,
            'password' => Str::random(40),
            'erp_customer_id' => $erpCustomerId,
            'erp_sync_status' => 'synced',
            'erp_synced_at' => now(),
        ]);
    }

    /**
     * بيتبعت بس لما custom_workflow_state فعليًا يتغيّر (شرط على مستوى الـ Webhook في
     * ERPNext نفسه)، فمش محتاجين نتأكد تاني هنا.
     */
    private function handleSalesOrderEvent(array $data): void
    {
        $newStatus = $data['custom_workflow_state'] ?? null;

        if (! $newStatus) {
            return;
        }

        $userId = User::where('erp_customer_id', $data['customer'] ?? null)->value('id');

        $oldStatus = OrderStatusHistory::where('erp_order', $data['name'])
            ->latest('id')
            ->value('new_status');

        OrderStatusHistory::create([
            'erp_order' => $data['name'],
            'user_id' => $userId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => 'erpnext',
        ]);

        // نفس الحالة ممكن توصل مرتين (مثلًا الحالة الأولى اتسجلت وقت الإنشاء في SyncToERPNext)
        if ($oldStatus !== $newStatus) {
            app(OrderTelegramNotifier::class)->statusChanged($data['name'], $oldStatus, $newStatus, $userId ? User::find($userId) : null);

            if ($userId) {
                SendPushNotification::dispatch(
                    'order_status',
                    'تحديث على طلبك',
                    "طلبك {$data['name']} حالته الآن: {$newStatus}",
                    [$userId],
                    ['order' => $data['name']],
                );
            }
        }
    }

    /**
     * مفيش domain model جاهز لـ Wallet Transaction / Vet Appointment / Hotel Booking دلوقتي،
     * فبنسجّل الحدث في AuditLog عشان البيانات ما تتفقدش لحد ما نحتاج نبني consumer مخصص ليهم.
     */
    private function logAuditEvent(string $doctype, array $data): void
    {
        $userId = isset($data['customer'])
            ? User::where('erp_customer_id', $data['customer'])->value('id')
            : null;

        AuditLog::create([
            'user_id' => $userId,
            'action' => 'erp_sync',
            'doctype' => $doctype,
            'doc_name' => $data['name'] ?? null,
            'payload' => $data,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * ⚠️ مهمة send_appointment_reminders في ERPNext (يوميًا) بتستدعي هذا الـ endpoint
     * لأي موعد بكرة. شكل الـ payload هنا **تخمين معقول لسه محتاج تأكيد فعلي** —
     * أول استدعاء حقيقي يوصل لازم نتحقق من أسماء الحقول ونعدّل هنا لو اختلفت.
     * بيتبعت Push للزبون ويتحفظ في قائمة إشعاراته (SendPushNotification).
     */
    public function handleAppointmentReminder(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $data = json_decode($request->getContent(), true) ?? [];

        $userId = User::where('erp_customer_id', $data['erp_customer_id'] ?? null)->value('id');

        if (! $userId) {
            Log::warning('[Webhook:appointment-reminder] مفيش عميل مطابق: '.json_encode($data));

            return response()->json(['status' => 'ignored']);
        }

        SendPushNotification::dispatch(
            'vet_appointment_reminder',
            'تذكير بموعدك البيطري غدًا',
            trim(($data['service_type'] ?? 'موعد').' — الساعة '.($data['appointment_time'] ?? '')),
            [$userId],
            array_filter(['appointment' => $data['name'] ?? null]),
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * زر "إرسال" في شاشة "App Notification" في ERPNext — بيوصل هنا أو على /webhooks/erp
     * (doctype = "App Notification")، والاتنين موقّعين بنفس سر الويبهوك.
     */
    public function handlePushNotification(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        return $this->processAppNotification(json_decode($request->getContent(), true) ?? []);
    }

    /**
     * شكل ERPNext: send_to (all|specific) + customer_ids + action {type, ...}. الشكل القديم
     * (target/customers/data) لسه مقبول. الإرسال فوري (مش طابور) لأن ERPNext بيسجّل
     * success_count/failed_count من الرد على المستند ويقفله.
     */
    private function processAppNotification(array $data): JsonResponse
    {
        $data['send_to'] ??= ($data['target'] ?? null) === 'customers' ? 'specific' : ($data['target'] ?? null);
        $data['customer_ids'] ??= $data['customers'] ?? [];

        $validator = validator($data, [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:1000'],
            'image' => ['nullable', 'string', 'max:500'],
            'send_to' => ['required', 'in:all,specific'],
            'customer_ids' => ['required_if:send_to,specific', 'array'],
            'customer_ids.*' => ['string'],
            'action' => ['nullable', 'array'],
            'data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success_count' => 0,
                'failed_count' => 0,
                'error' => $validator->errors()->first(),
            ], 422);
        }

        $firebase = app(FirebaseService::class);

        if (! $firebase->enabled()) {
            return response()->json(['success_count' => 0, 'failed_count' => 0, 'error' => 'Firebase غير مضبوط على السيرفر'], 503);
        }

        // صورة من ERPNext بتيجي مسار نسبي (/files/x.png) — FCM محتاج رابط كامل عام
        $image = $data['image'] ?? null;
        if ($image && str_starts_with($image, '/')) {
            $image = rtrim((string) config('erpnext.url'), '/').$image;
        }

        $userIds = null;
        $unknown = [];

        if ($data['send_to'] === 'specific') {
            $users = User::whereIn('erp_customer_id', $data['customer_ids'])->pluck('id', 'erp_customer_id');
            $userIds = $users->values()->all();
            $unknown = array_values(array_diff($data['customer_ids'], $users->keys()->all()));
        }

        try {
            $counts = (new SendPushNotification(
                $data['send_to'] === 'all' ? 'broadcast' : 'custom',
                $data['title'],
                $data['body'],
                $userIds,
                [...$this->notificationAction($data), ...($data['data'] ?? []), 'reference' => $data['name'] ?? $data['reference'] ?? null],
                $image,
            ))->handle($firebase);
        } catch (\Throwable $e) {
            Log::error('[Push] فشل إرسال App Notification: '.$e->getMessage());

            return response()->json(['success_count' => 0, 'failed_count' => 0, 'error' => 'تعذر الإرسال لـ Firebase'], 502);
        }

        return response()->json([
            'success_count' => $counts['sent'],
            'failed_count' => $counts['failed'],
            // زبائن في ERPNext ما عندهمش حساب في التطبيق — ما وصلهمش حاجة
            'unknown_customers' => $unknown,
        ]);
    }

    /**
     * action من ERPNext ← data للتطبيق (strings): action = none|item|item_group|url + القيمة.
     * للمنتج بنضيف product_id كمان، لأن التطبيق بيفتح المنتج بـ GET /products/{id}.
     */
    private function notificationAction(array $data): array
    {
        $action = $data['action'] ?? [];
        $type = $action['type'] ?? 'none';

        if ($type === 'none') {
            return [];
        }

        $value = $action['value'] ?? $action[$type] ?? $action['item_code'] ?? $action['url'] ?? null;

        $result = [
            ...array_filter($action, fn ($v, $k) => $k !== 'type' && is_scalar($v), ARRAY_FILTER_USE_BOTH),
            'action' => $type,
        ];

        match ($type) {
            'item' => $result += [
                'item_code' => $value,
                'product_id' => $this->appProductId($value),
            ],
            'item_group' => $result += ['item_group' => $value],
            'url' => $result += ['url' => $value],
            default => null,
        };

        return array_filter($result, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * id اللي التطبيق يفتحه بـ GET /products/{id}: لو الموظف اختار وزن (Variant)، بنفتح
     * المنتج الأساسي (Template) اللي فيه الأوزان — وده اللي بيظهر في التطبيق. لو المنتج
     * مخفي أو موقوف بنرجّع null، فالإشعار يفتح شاشة الإشعارات بدل "المنتج غير متاح".
     */
    private function appProductId(?string $itemCode): ?int
    {
        if (! $itemCode) {
            return null;
        }

        $product = Product::where('item_code', $itemCode)->orWhere('erp_name', $itemCode)->first();

        if ($product?->variant_of) {
            $product = Product::where('erp_name', $product->variant_of)->first();
        }

        return $product && Product::listable()->whereKey($product->id)->exists() ? $product->id : null;
    }

    private function verifySignature(Request $request): void
    {
        $signature = $request->header('X-Frappe-Webhook-Signature');
        $secret = (string) config('erpnext.webhook_secret');

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        abort_unless($signature && hash_equals($expected, $signature), 401, 'invalid signature');
    }

    /**
     * ⚠️ الحذف (LoyalPet Item Sync (Delete)) بيبعت payload مختصر عمدًا (بدون item_name) —
     * ده الفاصل الوحيد اللي عندنا نفرّق بيه حذف عن إنشاء/تعديل، فبنسوفت-ديليت
     * (is_active=false) بدل ما نمسح الصف فعليًا، عشان الطلبات القديمة تفضل واضحة.
     */
    private function handleItemUpdate(array $data, ERPNextService $erp): void
    {
        $deactivate = function () use ($data) {
            Product::where('erp_name', $data['name'])->update(['is_active' => false, 'updated_at' => now()]);
            Bundle::where('item_code', $data['name'])->update(['is_active' => false, 'updated_at' => now()]);
        };

        if (! array_key_exists('item_name', $data)) {
            $deactivate();

            return;
        }

        // الـ Webhook Data في ERPNext بيبعت قائمة حقول محددة — أي حقل مش فيها (custom_is_featured،
        // custom_show_in_app...) كان بيتسجل بالقيمة الافتراضية مع كل تعديل: المميز يرجع
        // "مش مميز" والمخفي يرجع يظهر. بنجيب الصنف كامل من ERPNext ونعتمد على "name" بس.
        try {
            $data = $erp->get('Item', $data['name']);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }

            $deactivate();

            return;
        }

        // اسم الباقة وسعرها وتفعيلها جايين من الصنف اللي بيمثلها (new_item_code)، مش من
        // Product Bundle نفسه — فتعديل الصنف لازم يحدّث الباقة كمان، وإلا تفضل بالقديم
        Bundle::where('item_code', $data['item_code'] ?? $data['name'])->update([
            'title' => $data['item_name'] ?? $data['name'],
            'price' => $data['standard_rate'] ?? 0,
            'is_active' => ! ($data['disabled'] ?? false),
            'updated_at' => now(),
        ]);

        Product::updateOrCreate(
            ['erp_name' => $data['name']],
            [
                'item_code' => $data['item_code'] ?? null,
                'item_name' => $data['item_name'] ?? $data['name'],
                'item_group_erp_name' => ($data['item_group'] ?? null) ?: null,
                'variant_of' => ($data['variant_of'] ?? null) ?: null,
                'attributes' => $this->cleanAttributes($data['attributes'] ?? [], $erp),
                'description' => ($data['description'] ?? null) ?: null,
                'image_path' => ($data['image'] ?? null) ?: null,
                'price' => $data['standard_rate'] ?? 0,
                'is_active' => ! ($data['disabled'] ?? false),
                'show_in_app' => (bool) ($data['custom_show_in_app'] ?? true),
                'is_featured' => (bool) ($data['custom_is_featured'] ?? false),
                'featured_order' => (int) ($data['custom_featured_order'] ?? 0),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * صفوف attributes من Frappe فيها حقول داخلية زيادة (creation, owner, idx...) —
     * بنسيب بس attribute/attribute_value. لو الصف من Template من غير قيمة محددة
     * (attribute_value فاضي — بيوصف بس "الأنواع الممكنة")، بنجيب القيم الممكنة كلها
     * من Item Attribute (زي "دجاج"، "تونة"...) بدل ما نرجّع null من غير فايدة.
     */
    private function cleanAttributes(array $rows, ERPNextService $erp): array
    {
        return array_map(function (array $row) use ($erp) {
            $name = $row['attribute'] ?? null;
            $value = $row['attribute_value'] ?? null;

            if (! $value && $name) {
                try {
                    $values = $erp->getItemAttributeValues($name);
                } catch (\Throwable $e) {
                    Log::warning('[Webhook:erp] فشل جلب قيم الخاصية '.$name.': '.$e->getMessage());
                    $values = [];
                }
            }

            return [
                'attribute' => $name,
                'attribute_value' => $value,
                'values' => $value ? [] : ($values ?? []),
            ];
        }, $rows);
    }

    private function handleItemGroupUpdate(array $data): void
    {
        ItemGroup::updateOrCreate(
            ['erp_name' => $data['name']],
            [
                'parent_erp_name' => $data['parent_item_group'] ?: null,
                'is_group' => (bool) ($data['is_group'] ?? false),
                'image_path' => $data['image'] ?: null,
                'is_active' => true,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * لو الويبهوك العادي (بالاسم الجديد) وصل قبل حدث الـ rename، يبقى السجل الجديد موجود
     * أصلًا بأحدث بيانات — فبنمسح القديم بس. في الحالتين بننقل المنتجات والفروع للاسم
     * الجديد. آمنة لو اتكررت (نفس الحدث مرتين ما بيبوظش حاجة). ولو الاسم القديم أصلًا
     * مش موجود عندنا (الكاش مش متزامن) ولا الجديد، بنجيب المجموعة من ERPNext ونضيفها.
     */
    /**
     * إعادة تسمية صنف في ERPNext بتغيّر الـ name والـ item_code — من غيرها المنتج يفضل عندنا
     * بالكود القديم، والسلة والطلبات تتبعت بيه وERPNext يرفض ("Item ... not found").
     * بنحدّث المنتج (نفس الـ id عشان المفضلة)، والأوزان التابعة، والسلات، ومحتويات الباقات،
     * والطلبات اللي لسه في الطابور.
     */
    private function handleItemRename(array $data, ERPNextService $erp): void
    {
        $oldName = $data['old_name'] ?? null;
        $newName = $data['new_name'] ?? null;

        abort_unless($oldName && $newName, 422, 'old_name و new_name مطلوبين');

        if ($oldName === $newName) {
            return;
        }

        DB::transaction(function () use ($oldName, $newName) {
            if (Product::where('erp_name', $newName)->exists()) {
                Product::where('erp_name', $oldName)->delete();
            } else {
                Product::where('erp_name', $oldName)->update(['erp_name' => $newName, 'item_code' => $newName, 'updated_at' => now()]);
            }

            Product::where('variant_of', $oldName)->update(['variant_of' => $newName, 'updated_at' => now()]);
            DB::table('cart_items')->where('erp_item_code', $oldName)->update(['erp_item_code' => $newName]);
            DB::table('bundle_items')->where('item_code', $oldName)->update(['item_code' => $newName]);
            Bundle::where('item_code', $oldName)->update(['item_code' => $newName]);

            PendingSync::where('doctype', 'Sales Order')
                ->whereIn('status', ['pending', 'failed'])
                ->get()
                ->each(function (PendingSync $sync) use ($oldName, $newName) {
                    $payload = $sync->payload;
                    $changed = false;

                    foreach ($payload['items'] ?? [] as $i => $item) {
                        if ($item['item_code'] === $oldName) {
                            $payload['items'][$i]['item_code'] = $newName;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $sync->update(['payload' => $payload]);
                    }
                });
        });

        // بنجيب الصنف كامل بالاسم الجديد (سعر/صورة/حقول مخصصة) — نفس مسار الويبهوك العادي
        $this->handleItemUpdate(['name' => $newName, 'item_name' => $newName], $erp);
    }

    private function handleItemGroupRename(array $data, ERPNextService $erp): void
    {
        $oldName = $data['old_name'] ?? null;
        $newName = $data['new_name'] ?? null;

        abort_unless($oldName && $newName, 422, 'old_name و new_name مطلوبين');

        if ($oldName === $newName) {
            return;
        }

        DB::transaction(function () use ($oldName, $newName) {
            if (ItemGroup::where('erp_name', $newName)->exists()) {
                ItemGroup::where('erp_name', $oldName)->delete();
            } else {
                ItemGroup::where('erp_name', $oldName)->update(['erp_name' => $newName, 'updated_at' => now()]);
            }

            ItemGroup::where('parent_erp_name', $oldName)->update(['parent_erp_name' => $newName, 'updated_at' => now()]);
            Product::where('item_group_erp_name', $oldName)->update(['item_group_erp_name' => $newName, 'updated_at' => now()]);
        });

        if (! ItemGroup::where('erp_name', $newName)->exists()) {
            $this->handleItemGroupUpdate($erp->get('Item Group', $newName));
        }
    }

    /**
     * الـ Webhook Data في ERPNext بيبعت قائمة حقول محددة (مش الدوك كله)، فأي حقل جديد
     * (زي custom_show_in_app) أو جدول items الفرعي ممكن ما يوصلش — عشان كده بنجيب
     * الدوك كامل من ERPNext ونعتمد على "name" بس من الـ payload، زي sync:bundles بالظبط.
     */
    private function handleProductBundleUpdate(array $data, ERPNextService $erp): void
    {
        // الحذف (on_trash) بيوصل بنفس الويبهوك — الباقة ما عادش موجودة في ERPNext (404)،
        // فبنمسحها هنا بدل ما الطلب يفشل والباقة تفضل ظاهرة في التطبيق
        try {
            $doc = $erp->get('Product Bundle', $data['name']);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }

            Bundle::where('erp_name', $data['name'])->delete();

            return;
        }

        $item = $erp->get('Item', $doc['new_item_code']);

        $bundle = Bundle::updateOrCreate(
            ['erp_name' => $doc['name']],
            [
                'item_code' => $doc['new_item_code'],
                'title' => $item['item_name'] ?? $doc['new_item_code'],
                'description' => ($doc['description'] ?? null) ?: null,
                'price' => $item['standard_rate'] ?? 0,
                'image_path' => ($doc['custom_image'] ?? null) ?: null,
                'is_active' => ! ($item['disabled'] ?? false),
                'show_in_app' => (bool) ($doc['custom_show_in_app'] ?? true),
                'updated_at' => now(),
            ]
        );

        $bundle->items()->delete();
        $bundle->items()->createMany(
            collect($doc['items'] ?? [])->map(fn (array $row) => [
                'item_code' => $row['item_code'],
                'qty' => $row['qty'] ?? 1,
            ])->all()
        );
    }

    /**
     * بيتبعت على أي إنشاء أو تعديل لمنطقة توصيل (سعر، تفعيل/تعطيل، اسم) — upsert
     * بـ erp_name زي باقي أنواع الكاتالوج. is_active=0 بيسيب السجل موجود بس نتجاهله
     * من قوائم الاختيار، مش حذف فعلي.
     */
    private function handleDeliveryZoneUpdate(array $data): void
    {
        DeliveryZone::updateOrCreate(
            ['erp_name' => $data['name']],
            [
                'zone_name' => $data['zone_name'],
                'delivery_price' => $data['delivery_price'] ?? 0,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_at' => now(),
            ]
        );
    }
}
