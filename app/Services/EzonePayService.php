<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class EzonePayService
{
    private function client(): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => config('ezonepay.api_key')])
            ->baseUrl(config('ezonepay.base_url'));
    }

    /**
     * رابط دفع لمرة واحدة (MaxUsageCount=1) — العميل يفتحه في WebView ويختار
     * وسيلة الدفع من صفحة Ezone Pay نفسها.
     */
    public function createPaymentLink(array $data): array
    {
        return $this->client()->post('/payment-link/new', [
            'MaxUsageCount' => 1,
            ...$data,
        ])->throw()->json();
    }

    /**
     * استعلام مباشر عن حالة معاملة أونلاين — مش جزء من مسار الويبهوك الأساسي (الويبهوك
     * موقّع أصلًا بـ HMAC وده كافي)، متاحة للمطابقة/التصحيح اليدوي لو احتجنا.
     */
    public function getOnlineTransaction(int $transactionId): array
    {
        return $this->client()->get("/payments/transactions/{$transactionId}/online")->throw()->json();
    }

    /**
     * تسجيل رابط الويبهوك بتاعنا عند Ezone Pay — بتُستدعى مرة واحدة بس، عبر أمر
     * Artisan (ezonepay:subscribe-webhook)، مش جزء من تدفق الطلبات العادي.
     */
    public function subscribeWebhook(string $url): array
    {
        return $this->client()->post('/Webhook/subscribe', [
            'Url' => $url,
            'Event' => 2,
        ])->throw()->json();
    }
}
