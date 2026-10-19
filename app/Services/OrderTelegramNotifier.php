<?php

namespace App\Services;

use App\Jobs\SendTelegramNotification;
use App\Models\PendingSync;
use App\Models\Product;
use App\Models\User;

/**
 * إشعارات الطلبات على قروب التليجرام — كل الطلبات بتتمثل بنفس الشكل الموحّد
 * (مفاتيح payload الإنشاء: recipient_name, delivery_address, items...)، سواء جاية
 * من الطابور أو من Sales Order في ERPNext (fromErpDoc).
 */
class OrderTelegramNotifier
{
    private const LABELS = [
        'recipient_name' => 'المستلم',
        'recipient_phone' => 'هاتف المستلم',
        'delivery_address' => 'العنوان',
        'delivery_date' => 'تاريخ التوصيل',
        'payment_method' => 'الدفع',
        'notes' => 'ملاحظات',
    ];

    public function created(PendingSync $sync, string $erpName): void
    {
        $order = $sync->payload;
        $receipt = $order['payment_method'] === 'bank_transfer' ? ($order['transfer_receipt_path'] ?? null) : null;

        $text = "🆕 <b>طلب جديد — {$this->e($erpName)}</b>\n\n"
            .$this->customerLine($sync->user)
            .$this->details($order);

        dispatch(new SendTelegramNotification($text, $receipt, deletePhoto: true));
    }

    public function createFailed(PendingSync $sync, string $reason): void
    {
        $text = "⚠️ <b>فشل إنشاء طلب (مرجع #{$sync->id})</b>\n"
            ."السبب: {$this->e($reason)}\n\n"
            .$this->customerLine($sync->user)
            .$this->details($sync->payload)
            ."\n\nالطلب ما وصلش للنظام — تواصل مع الزبون.";

        dispatch(new SendTelegramNotification($text));
    }

    /**
     * @param  array  $before  الطلب قبل التعديل (الشكل الموحّد)
     * @param  array  $changes  الحقول اللي اتعدلت بس (items = القائمة الجديدة كاملة)
     */
    public function updated(string $orderLabel, User $user, array $before, array $changes, bool $newReceipt = false): void
    {
        $lines = [];

        foreach (self::LABELS as $key => $label) {
            if (! array_key_exists($key, $changes) || ($changes[$key] ?? null) == ($before[$key] ?? null)) {
                continue;
            }

            $lines[] = "• {$label}: {$this->value($key, $before[$key] ?? null)} ⬅️ {$this->value($key, $changes[$key])}";
        }

        if ($newReceipt) {
            $lines[] = '• تم رفع إيصال حوالة جديد';
        }

        $text = "✏️ <b>تعديل طلب — {$this->e($orderLabel)}</b>\n\n"
            .$this->customerLine($user)
            .($lines ? "<b>التعديلات:</b>\n".implode("\n", $lines)."\n" : '');

        if (isset($changes['items'])) {
            $text .= "\n<b>الأصناف بعد التعديل:</b>\n".$this->items($changes['items']);
        }

        dispatch(new SendTelegramNotification($text));
    }

    public function cancelled(string $orderLabel, User $user): void
    {
        $text = "❌ <b>إلغاء طلب — {$this->e($orderLabel)}</b>\n\n"
            .$this->customerLine($user)
            .'الزبون لغى الطلب من التطبيق.';

        dispatch(new SendTelegramNotification($text));
    }

    public function statusChanged(string $erpName, ?string $old, string $new, ?User $user): void
    {
        $text = "🔄 <b>تغيّر حالة طلب — {$this->e($erpName)}</b>\n\n"
            .($user ? $this->customerLine($user) : '')
            .'الحالة: '.($old ? $this->e($old).' ⬅️ ' : '')."<b>{$this->e($new)}</b>";

        dispatch(new SendTelegramNotification($text));
    }

    /**
     * Sales Order من ERPNext ← نفس الشكل الموحّد بتاع payload الإنشاء.
     */
    public static function fromErpDoc(array $doc): array
    {
        return [
            'recipient_name' => $doc['custom_recipient_name'] ?? null,
            'recipient_phone' => $doc['custom_recipient_phone'] ?? null,
            'delivery_address' => $doc['custom_delivery_address'] ?? null,
            'delivery_date' => $doc['delivery_date'] ?? null,
            'payment_method' => $doc['custom_payment_method'] ?? null,
            'notes' => $doc['custom_notes'] ?? null,
            'items' => array_map(fn (array $row) => [
                'item_code' => $row['item_code'],
                'qty' => $row['qty'],
                'rate' => $row['rate'],
            ], $doc['items'] ?? []),
        ];
    }

    private function customerLine(?User $user): string
    {
        return $user ? "👤 الزبون: {$this->e($user->name)} — {$this->e($user->phone)}\n" : '';
    }

    private function details(array $order): string
    {
        $text = "📦 المستلم: {$this->e($order['recipient_name'] ?? '-')} — {$this->e($order['recipient_phone'] ?? '-')}\n"
            ."📍 العنوان: {$this->e($order['delivery_address'] ?? '-')}\n"
            ."📅 تاريخ التوصيل: {$this->e($order['delivery_date'] ?? '-')}\n"
            ."💳 الدفع: {$this->value('payment_method', $order['payment_method'] ?? null)}\n\n"
            ."🛒 <b>الأصناف:</b>\n".$this->items($order['items'] ?? []);

        if (! empty($order['notes'])) {
            $text .= "\n📝 ملاحظات: {$this->e($order['notes'])}";
        }

        return $text;
    }

    private function items(array $items): string
    {
        $names = Product::whereIn('item_code', array_column($items, 'item_code'))->pluck('item_name', 'item_code');
        $total = 0;
        $lines = [];

        foreach ($items as $item) {
            $amount = $item['qty'] * $item['rate'];
            $total += $amount;
            $lines[] = '• '.$this->e($names[$item['item_code']] ?? $item['item_code'])
                .' × '.$this->number($item['qty']).' = '.number_format($amount, 2);
        }

        return implode("\n", $lines)."\n\n💰 <b>المجموع: ".number_format($total, 2).' د.ل</b>';
    }

    private function value(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($key === 'payment_method') {
            return $this->e(config("payment.methods.{$value}", $value));
        }

        return $this->e((string) $value);
    }

    private function number(float|int|string $qty): string
    {
        return rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');
    }

    private function e(?string $text): string
    {
        return TelegramService::escape($text);
    }
}
