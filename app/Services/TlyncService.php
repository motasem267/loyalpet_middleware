<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TlyncService
{
    private function client(): PendingRequest
    {
        // TLYNC بيتطلب application/x-www-form-urlencoded مش JSON — asForm() بتظبط الهيدر والترميز
        return Http::asForm()
            ->withToken(config('tlync.api_token'))
            ->baseUrl(config('tlync.base_url'));
    }

    /**
     * يبدأ جلسة دفع ويرجّع رابط صفحة الدفع المُستضافة عند TLYNC.
     */
    public function initiatePayment(array $data): array
    {
        return $this->client()->post('/payment/initiate', [
            'id' => config('tlync.store_id'),
            ...$data,
        ])->throw()->json();
    }

    /**
     * تحقّق مباشر من TLYNC بحالة معاملة — المصدر الوحيد الموثوق لتأكيد الدفع
     * (الويبهوك نفسه غير موقّع، فما نعتمدش عليه لوحده).
     */
    public function getReceipt(string $customRef): array
    {
        return $this->client()->post('/receipt/transaction', [
            'store_id' => config('tlync.store_id'),
            'custom_ref' => $customRef,
        ])->throw()->json();
    }
}
