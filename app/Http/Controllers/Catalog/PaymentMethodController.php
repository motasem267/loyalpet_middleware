<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PaymentMethodController extends Controller
{
    /**
     * طرق الدفع + بيانات الحساب البنكي للحوالة — من الإعدادات مش ثابتة في التطبيق،
     * عشان تتغير (رقم الحساب مثلًا) من غير تحديث التطبيق.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => collect(config('payment.methods'))
                ->map(fn (string $name, string $code) => [
                    'code' => $code,
                    'name' => $name,
                    'requires_receipt' => $code === 'bank_transfer',
                ])
                ->values(),
            'bank_account' => config('payment.bank'),
        ]);
    }
}
