<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bundle;
use App\Models\DeliveryZone;
use App\Models\ItemGroup;
use App\Models\Notification;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\ERPNextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        match ($doctype) {
            'Customer' => $this->handleCustomerEvent($data),
            'Sales Order' => $this->handleSalesOrderEvent($data),
            'Item' => $this->handleItemUpdate($data),
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
     * حاليًا بننشئ إشعار داخل التطبيق بس (Notification record) — إرسال Push فعلي
     * عبر FCM لسه مش متبني (محتاج Firebase Admin SDK + بيانات اعتماد منفصلة).
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

        Notification::create([
            'user_id' => $userId,
            'type' => 'vet_appointment_reminder',
            'title' => 'تذكير بموعدك البيطري غدًا',
            'body' => trim(($data['service_type'] ?? 'موعد').' — الساعة '.($data['appointment_time'] ?? '')),
            'data' => $data,
        ]);

        return response()->json(['status' => 'ok']);
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
    private function handleItemUpdate(array $data): void
    {
        if (! array_key_exists('item_name', $data)) {
            Product::where('erp_name', $data['name'])->update(['is_active' => false, 'updated_at' => now()]);

            return;
        }

        Product::updateOrCreate(
            ['erp_name' => $data['name']],
            [
                'item_code' => $data['item_code'] ?? null,
                'item_name' => $data['item_name'] ?? $data['name'],
                'item_group_erp_name' => $data['item_group'] ?: null,
                'variant_of' => $data['variant_of'] ?: null,
                'attributes' => $this->cleanAttributes($data['attributes'] ?? []),
                'description' => $data['description'] ?: null,
                'image_path' => $data['image'] ?: null,
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
     * بنسيب بس attribute/attribute_value.
     */
    private function cleanAttributes(array $rows): array
    {
        return array_map(fn (array $row) => [
            'attribute' => $row['attribute'] ?? null,
            'attribute_value' => $row['attribute_value'] ?? null,
        ], $rows);
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

    private function handleProductBundleUpdate(array $data, ERPNextService $erp): void
    {
        $item = $erp->get('Item', $data['new_item_code']);

        Bundle::updateOrCreate(
            ['erp_name' => $data['name']],
            [
                'item_code' => $data['new_item_code'],
                'title' => $item['item_name'] ?? $data['new_item_code'],
                'description' => $data['description'] ?: null,
                'price' => $item['standard_rate'] ?? 0,
                'image_path' => $data['custom_image'] ?: null,
                'is_active' => ! ($item['disabled'] ?? false),
                'updated_at' => now(),
            ]
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
