<?php

namespace App\Jobs;

use App\Models\OrderStatusHistory;
use App\Models\PendingSync;
use App\Services\ERPNextService;
use App\Services\OrderTelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Storage;

class SyncToERPNext implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public PendingSync $sync) {}

    public function handle(ERPNextService $erp): void
    {
        // حجز ذرّي (update مشروط) بدل فحص ثم تحديث — عشان لو الزبون لغى/عدّل الطلب في
        // نفس اللحظة (OrderController)، يا الإلغاء يكسب يا الإرسال، مش الاتنين.
        $claimed = PendingSync::whereKey($this->sync->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->update(['status' => 'processing']);

        if (! $claimed) {
            return;
        }

        $this->sync->refresh();

        try {
            match ($this->sync->doctype) {
                'Sales Order' => $this->processSalesOrder($erp),
                'Customer' => $this->processCustomerUpdate($erp),
                'Issue' => $this->processIssueCreate($erp),
                'Hotel Booking' => $this->processHotelBooking($erp),
                'Vet Appointment' => $this->processVetAppointment($erp),
                default => throw new \RuntimeException("Unsupported doctype: {$this->sync->doctype}"),
            };
        } catch (\Throwable $e) {
            $this->sync->increment('attempts');
            $this->sync->update(['status' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * كل نداءات /api/method/... عند Frappe بترجع النتيجة ملفوفة جوّه مفتاح
     * "message" — بعض الردود اتوصفت لينا بدونه اختصارًا، فبنتعامل مع الاحتمالين.
     */
    private function unwrap(array $result): array
    {
        return $result['message'] ?? $result;
    }

    /**
     * loyalpet.api.orders.create_sales_order بيرفض طلب المحفظة لو الرصيد ما يكفيش
     * (frappe.throw "الرصيد غير كافٍ في المحفظة") — ده رفض نهائي مش عطل، فما بنعيدش
     * المحاولة: بنعلّم الطلب فشل ونحفظ الرسالة عشان تظهر للزبون، وهو يعدّل الطلب
     * (PATCH /orders/{reference}) فيرجع للطابور.
     */
    private function processSalesOrder(ERPNextService $erp): void
    {
        $payload = $this->sync->payload;
        $user = $this->sync->user;

        if (! $user->erp_customer_id) {
            throw new \RuntimeException('العميل لسه ما اتزامنش مع ERPNext');
        }

        // إيصال الحوالة: بيترفع مرة واحدة بس — الرابط بيتحفظ في الـ payload عشان لو
        // الإنشاء فشل واتعاد، ما نرفعش نفس الصورة تاني
        $isTransfer = $payload['payment_method'] === 'bank_transfer';

        if ($isTransfer && ! empty($payload['transfer_receipt_path']) && empty($payload['transfer_receipt_url'])) {
            $path = $payload['transfer_receipt_path'];
            $payload['transfer_receipt_url'] = $erp->uploadFile(Storage::path($path), basename($path));
            $this->sync->update(['payload' => $payload]);
        }

        try {
            $result = $this->unwrap($erp->callMethod('loyalpet.api.orders.create_sales_order', [
                'customer_id' => $user->erp_customer_id,
                'items' => $payload['items'],
                'payment_method' => $payload['payment_method'],
                'recipient_name' => $payload['recipient_name'],
                'recipient_phone' => $payload['recipient_phone'],
                'delivery_address' => $payload['delivery_address'],
                'delivery_date' => $payload['delivery_date'],
                'notes' => $payload['notes'] ?? null,
                ...($isTransfer ? ['transfer_receipt' => $payload['transfer_receipt_url'] ?? null] : []),
            ]));
        } catch (RequestException $e) {
            if ($e->response->status() >= 500) {
                throw $e;
            }

            $message = ERPNextService::errorMessage($e->response->json() ?? []) ?? 'تعذر إنشاء الطلب';

            $this->sync->update([
                'status' => 'failed',
                'attempts' => $this->tries,
                'error' => $message,
                'payload' => [...$payload, 'rejection_message' => $message],
            ]);

            app(OrderTelegramNotifier::class)->createFailed($this->sync->fresh(), $message);

            return;
        }

        $this->sync->update([
            'status' => 'done',
            'erp_name' => $result['name'],
            'synced_at' => now(),
        ]);

        // الإيصال المحلي بيتبعت للتليجرام مع الإشعار وبعدها بيتمسح (بقى في ERPNext خلاص)
        app(OrderTelegramNotifier::class)->created($this->sync->fresh(), $result['name']);

        OrderStatusHistory::create([
            'erp_order' => $result['name'],
            'user_id' => $this->sync->user_id,
            'old_status' => null,
            'new_status' => $result['custom_workflow_state'] ?? $result['workflow_state'] ?? $result['status'] ?? 'قيد التوصيل',
            'changed_by' => 'system',
        ]);
    }

    /**
     * تحديث بيانات بسيطة على Customer في ERPNext (مثلًا الاسم بعد تعديل الملف الشخصي).
     */
    private function processCustomerUpdate(ERPNextService $erp): void
    {
        $erp->update('Customer', $this->sync->erp_name, $this->sync->payload);

        $this->sync->update([
            'status' => 'done',
            'synced_at' => now(),
        ]);
    }

    /**
     * إنشاء تذكرة دعم فني (Issue) — DocType الدعم الفني القياسي في ERPNext
     * (موديول "Support" في الواجهة، بس اسم الـ DocType الفعلي "Issue").
     */
    private function processIssueCreate(ERPNextService $erp): void
    {
        $payload = $this->sync->payload;
        $user = $this->sync->user;

        if (! $user->erp_customer_id) {
            throw new \RuntimeException('العميل لسه ما اتزامنش مع ERPNext');
        }

        $result = $erp->create('Issue', [
            'subject' => $payload['subject'],
            'description' => $payload['description'],
            'customer' => $user->erp_customer_id,
        ]);

        $this->sync->update([
            'status' => 'done',
            'erp_name' => $result['name'],
            'synced_at' => now(),
        ]);
    }

    /**
     * loyalpet.api.hotel.create_hotel_booking — المبلغ يتحسب تلقائيًا
     * (ليالي × سعر الغرفة + الخدمات) على جانب ERPNext.
     */
    private function processHotelBooking(ERPNextService $erp): void
    {
        $payload = $this->sync->payload;
        $user = $this->sync->user;

        if (! $user->erp_customer_id) {
            throw new \RuntimeException('العميل لسه ما اتزامنش مع ERPNext');
        }

        $result = $this->unwrap($erp->callMethod('loyalpet.api.hotel.create_hotel_booking', [
            'customer_id' => $user->erp_customer_id,
            'room' => $payload['room'],
            'check_in_date' => $payload['check_in_date'],
            'check_out_date' => $payload['check_out_date'],
            'payment_method' => $payload['payment_method'],
            'services' => $payload['services'] ?? [],
            'notes' => $payload['notes'] ?? null,
        ]));

        $this->sync->update([
            'status' => 'done',
            'erp_name' => $result['name'],
            'synced_at' => now(),
        ]);
    }

    /**
     * loyalpet.api.vet.create_vet_appointment.
     */
    private function processVetAppointment(ERPNextService $erp): void
    {
        $payload = $this->sync->payload;
        $user = $this->sync->user;

        if (! $user->erp_customer_id) {
            throw new \RuntimeException('العميل لسه ما اتزامنش مع ERPNext');
        }

        $result = $this->unwrap($erp->callMethod('loyalpet.api.vet.create_vet_appointment', [
            'customer_id' => $user->erp_customer_id,
            'service_type' => $payload['service_type'],
            'appointment_date' => $payload['appointment_date'],
            'appointment_time' => $payload['appointment_time'],
            'doctor' => $payload['doctor'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]));

        $this->sync->update([
            'status' => 'done',
            'erp_name' => $result['name'],
            'synced_at' => now(),
        ]);
    }
}
