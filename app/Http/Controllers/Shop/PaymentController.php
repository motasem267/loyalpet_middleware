<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\CreditWalletFromPayment;
use App\Models\PaymentSession;
use App\Services\EzonePayService;
use App\Services\TlyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    /**
     * بدء عملية شحن محفظة — يرجّع رابط صفحة الدفع المُستضافة عند Ezone Pay (بديل TLYNC)،
     * التطبيق يفتحه في WebView. رابط لمرة واحدة (MaxUsageCount=1) بمبلغ العملية بالظبط.
     */
    public function topUp(Request $request, EzonePayService $ezonePay): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $user = $request->user();
        $customRef = 'TOPUP-'.Str::upper(Str::random(24));

        $session = PaymentSession::create([
            'user_id' => $user->id,
            'idempotency_key' => $customRef,
            'amount' => $validated['amount'],
            'purpose' => 'wallet_topup',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ]);

        [$firstName, $lastName] = $this->splitName($user->name);

        try {
            $result = $ezonePay->createPaymentLink([
                'Amount' => $validated['amount'],
                'OrderReference' => $customRef,
                'RedirectUrl' => route('payment.complete', ['reference' => $customRef]),
                'Customer' => [
                    'FirstName' => $firstName,
                    'LastName' => $lastName,
                    'PhoneNumber' => $user->phone,
                ],
            ]);
        } catch (\Throwable $e) {
            $session->update(['status' => 'failed']);

            Log::error('[EzonePay] فشل بدء عملية الدفع: '.$e->getMessage());

            throw ValidationException::withMessages([
                'amount' => ['تعذر بدء عملية الدفع، حاول مرة أخرى'],
            ]);
        }

        $session->update([
            'mypay_session_id' => $result['Id'] ?? null,
            'mypay_payment_url' => $result['Link'] ?? null,
        ]);

        return response()->json([
            'reference' => $session->id,
            'payment_url' => $result['Link'] ?? null,
        ], 201);
    }

    private function splitName(string $name): array
    {
        $parts = explode(' ', trim($name), 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    /**
     * ⚠️ الويبهوك ده بدون توقيع (TLYNC ما بيوفروش HMAC زي ERPNext/iSend) — نعامله
     * كـ "تنبيه" بس، ونتحقق فعليًا من TLYNC نفسه عبر getReceipt() قبل ما نصدّق
     * إن الدفع نجح. أي حد يعرف الرابط يقدر يبعتله body وهمي، فما نعتمدش عليه لوحده.
     */
    public function handleTlyncWebhook(Request $request, TlyncService $tlync): JsonResponse
    {
        $customRef = $request->input('custom_ref');

        if (! $customRef) {
            Log::warning('[TLYNC:webhook] وصل بدون custom_ref');

            return response()->json(['status' => 'ignored']);
        }

        $session = PaymentSession::where('idempotency_key', $customRef)->first();

        if (! $session) {
            Log::warning('[TLYNC:webhook] مفيش PaymentSession مطابق لـ custom_ref: '.$customRef);

            return response()->json(['status' => 'ignored']);
        }

        $session->update(['webhook_received_at' => now()]);

        if ($session->status === 'paid') {
            return response()->json(['status' => 'ok']); // معالج قبل كده، تجاهل تكرار الويبهوك
        }

        try {
            $receipt = $tlync->getReceipt($customRef);
        } catch (\Throwable $e) {
            Log::error('[TLYNC:webhook] فشل التحقق من الإيصال لـ '.$customRef.': '.$e->getMessage());

            return response()->json(['status' => 'error'], 500);
        }

        if (($receipt['result'] ?? null) === 'success') {
            // our_ref هو معرّف المعاملة الفريد من TLYNC نفسه — ده اللي لازم يتبعت
            // لـ ERPNext كـ reference، مش idempotency_key بتاعنا إحنا.
            $session->update([
                'status' => 'paid',
                'paid_at' => now(),
                'gateway_reference' => $request->input('our_ref'),
            ]);

            CreditWalletFromPayment::dispatch($session);
        } elseif (($receipt['result'] ?? null) !== 'incomplete') {
            $session->update(['status' => 'failed']);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * ⚠️ بعكس TLYNC، Ezone Pay بيوقّع الويبهوك فعليًا (X-Signature = HMAC-SHA256 على
     * الـ raw body، بالـ SecretKey الراجع وقت POST /Webhook/subscribe) — التحقق من
     * التوقيع كافي لتصديق الحدث، فمفيش نداء تحقق إضافي هنا (غير TLYNC).
     */
    public function handleEzonePayWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Signature');
        $secret = (string) config('ezonepay.webhook_secret');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        abort_unless($signature && hash_equals($expected, $signature), 401, 'invalid signature');

        $data = json_decode($request->getContent(), true) ?? [];

        if ((int) ($data['event'] ?? 0) !== 2) {
            return response()->json(['status' => 'ignored']);
        }

        $customRef = $data['orderReference'] ?? null;
        $transactionId = $data['transactionId'] ?? null;

        if (! $customRef || ! $transactionId) {
            Log::warning('[EzonePay:webhook] وصل بدون orderReference/transactionId: '.json_encode($data));

            return response()->json(['status' => 'ignored']);
        }

        $session = PaymentSession::where('idempotency_key', $customRef)->first();

        if (! $session) {
            Log::warning('[EzonePay:webhook] مفيش PaymentSession مطابق لـ orderReference: '.$customRef);

            return response()->json(['status' => 'ignored']);
        }

        $session->update(['webhook_received_at' => now()]);

        if ($session->status === 'paid') {
            return response()->json(['status' => 'ok']); // معالج قبل كده، تجاهل تكرار الويبهوك
        }

        // transactionId هو معرّف المعاملة الفريد من Ezone Pay نفسه — ده اللي بيتبعت
        // لـ ERPNext كـ reference، مش idempotency_key بتاعنا إحنا.
        $session->update([
            'status' => 'paid',
            'paid_at' => now(),
            'gateway_reference' => (string) $transactionId,
        ]);

        CreditWalletFromPayment::dispatch($session);

        return response()->json(['status' => 'ok']);
    }

    /**
     * صفحة عامة (بدون auth:sanctum) — بوابة الدفع بتحوّل متصفح/WebView العميل ليها
     * بعد الدفع. بتعرض الحالة الحالية للعملية، ولو لسه pending بتعمل refresh تلقائي
     * كام مرة (احتمال الويبهوك لسه ما وصلش).
     */
    public function complete(string $reference): View
    {
        $session = PaymentSession::where('idempotency_key', $reference)->first();

        return view('payment.complete', ['session' => $session]);
    }
}
