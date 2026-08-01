<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * حساب iSend الحالي في وضع "template_only" (باقة تجريبية) — أي رسالة نص حر
     * (send() تحت) بترفض في الإنتاج بـ 403. الـ OTP لازم يعدي عبر قالب معتمد.
     */
    public function sendOtp(string $phone, string $otp): bool
    {
        if (! config('sms.api_key')) {
            Log::info('[SMS:log-fallback:otp] '.$phone.' => '.$otp);

            return true;
        }

        try {
            $response = Http::withToken(config('sms.api_key'))
                ->post(config('sms.base_url').'/messages/send-template', [
                    'template_id' => 'otp_arabic_branded',
                    'to' => $this->toInternationalFormat($phone),
                    'parameters' => [
                        'company_name' => 'متجر Loyal Pet',
                        'otp' => $otp,
                    ],
                    'sender_id' => config('sms.sender_name'),
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('[SMS:iSend] تعذر الاتصال ببوابة الرسائل: '.$e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::error('[SMS:iSend] فشل إرسال OTP لـ '.$phone.': '.$response->body());
        }

        return $response->successful();
    }

    /**
     * نص حر — يشتغل بس لو الحساب خارج وضع template-only (باقة مدفوعة بدون هذا القيد).
     * سايبينها موجودة لاستخدامات مستقبلية غير الـ OTP (إشعارات عامة مثلًا).
     */
    public function send(string $phone, string $message): bool
    {
        if (! config('sms.api_key')) {
            Log::info('[SMS:log-fallback] '.$phone.' => '.$message);

            return true;
        }

        try {
            $response = Http::withToken(config('sms.api_key'))
                ->post(config('sms.base_url').'/messages', [
                    'to' => $this->toInternationalFormat($phone),
                    'from' => config('sms.sender_name'),
                    'body' => $message,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('[SMS:iSend] تعذر الاتصال ببوابة الرسائل: '.$e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::error('[SMS:iSend] فشل إرسال الرسالة لـ '.$phone.': '.$response->body());
        }

        return $response->successful();
    }

    /**
     * iSend بيتطلب صيغة 2189XXXXXXXX (كود الدولة 218 بدون الصفر البادئ)،
     * وأرقامنا مخزّنة محليًا بصيغة 09XXXXXXXX.
     */
    private function toInternationalFormat(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return str_starts_with($digits, '218') ? $digits : '218'.ltrim($digits, '0');
    }
}
