<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\CreditWalletFromPayment;
use App\Models\PaymentSession;
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
     * بدء عملية شحن محفظة — يرجّع رابط صفحة الدفع المُستضافة عند TLYNC،
     * التطبيق يفتحه في WebView.
     */
    public function topUp(Request $request, TlyncService $tlync): JsonResponse
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

        try {
            $result = $tlync->initiatePayment([
                'amount' => $validated['amount'],
                'phone' => $user->phone,
                'email' => config('tlync.billing_email'),
                'custom_ref' => $customRef,
                'backend_url' => url('/api/v1/webhooks/tlync'),
                'frontend_url' => route('payment.complete', ['reference' => $customRef]),
            ]);
        } catch (\Throwable $e) {
            $session->update(['status' => 'failed']);

            Log::error('[TLYNC] فشل بدء عملية الدفع: '.$e->getMessage());

            throw ValidationException::withMessages([
                'amount' => ['تعذر بدء عملية الدفع، حاول مرة أخرى'],
            ]);
        }

        $session->update(['mypay_payment_url' => $result['url'] ?? null]);

        return response()->json([
            'reference' => $session->id,
            'payment_url' => $result['url'] ?? null,
        ], 201);
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
     * صفحة عامة (بدون auth:sanctum) — TLYNC يحوّل متصفح/WebView العميل ليها بعد
     * الدفع. بتعرض الحالة الحالية للعملية، ولو لسه pending بتعمل refresh تلقائي
     * كام مرة (احتمال الويبهوك لسه ما وصلش).
     */
    public function complete(string $reference): View
    {
        $session = PaymentSession::where('idempotency_key', $reference)->first();

        return view('payment.complete', ['session' => $session]);
    }
}
