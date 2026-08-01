<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\ERPNextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function balance(Request $request, ERPNextService $erp): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->erp_customer_id, 422, 'الحساب لسه ما اتزامنش مع ERPNext');

        $result = $erp->callMethod('loyalpet.api.wallet.get_wallet_balance', [
            'erp_customer_id' => $user->erp_customer_id,
        ]);

        return response()->json(['data' => $result['message'] ?? $result]);
    }

    /**
     * loyalpet.api.wallet.get_wallet_transactions بياخد limit بس (بدون offset)،
     * فبنعمل الـ pagination من عندنا: نطلب limit = page * 10 ونقص الصفحة المطلوبة.
     */
    public function transactions(Request $request, ERPNextService $erp): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->erp_customer_id, 422, 'الحساب لسه ما اتزامنش مع ERPNext');

        $perPage = 10;
        $page = max(1, (int) $request->query('page', 1));

        $result = $erp->callMethod('loyalpet.api.wallet.get_wallet_transactions', [
            'erp_customer_id' => $user->erp_customer_id,
            'limit' => $page * $perPage,
        ]);

        $all = $result['message'] ?? $result;
        $all = is_array($all) ? array_values($all) : [];

        $items = array_slice($all, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => count($all) >= $page * $perPage,
            ],
        ]);
    }
}
