<?php

namespace App\Jobs;

use App\Models\PaymentSession;
use App\Services\ERPNextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreditWalletFromPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public PaymentSession $session) {}

    public function handle(ERPNextService $erp): void
    {
        $user = $this->session->user;

        if (! $user->erp_customer_id) {
            throw new \RuntimeException('العميل لسه ما اتزامنش مع ERPNext');
        }

        // reference لازم يكون معرّف المعاملة الفريد من TLYNC نفسه (gateway_reference)،
        // مش idempotency_key بتاعنا — ده اللي بيضمن الـ idempotency لو الـ job اتكرر.
        if (! $this->session->gateway_reference) {
            throw new \RuntimeException('مفيش gateway_reference لعملية الدفع #'.$this->session->id);
        }

        $erp->callMethod('loyalpet.api.wallet.credit_wallet', [
            'erp_customer_id' => $user->erp_customer_id,
            'amount' => (float) $this->session->amount,
            'reference' => $this->session->gateway_reference,
        ]);
    }
}
