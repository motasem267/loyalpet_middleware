<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * الكوبونات معرّفة ومحسوبة في ERPNext (Coupon Code + Pricing Rule على الطلب كامل) —
 * هنا بنتحقق منها عن طريق loyalpet.api.coupons.validate_coupon، وبنحسب الخصم محليًا
 * لعرض السلة: نسبة أو مبلغ ثابت على مجموع المنتجات (التوصيل برا الخصم).
 */
class CouponService
{
    public const BLOCKED_REASON = 'الكوبونات ما تنطبقش على الطلبات اللي فيها باقات';

    public const REMOVED_FOR_PACKAGE = 'تم إزالة الكوبون لأن السلة فيها باقة';

    public const ORDER_HAS_COUPON = 'الطلب فيه كوبون، ما تقدرش تضيف باقة. شيل الكوبون أولاً';

    public function __construct(private ERPNextService $erp) {}

    /**
     * "باقة" = الباقات اللي تظهر في قسم الباقات في التطبيق (show_in_app) — أصناف الأوزان
     * مسجلة Product Bundle في ERPNext بس show_in_app مطفية، فهي منتجات عادية يشملها الكوبون.
     * نفس التعريف مطبّق في ERPNext.
     */
    public static function hasPackage(array $itemCodes): bool
    {
        return $itemCodes !== []
            && Bundle::where('show_in_app', true)->whereIn('item_code', $itemCodes)->exists();
    }

    /**
     * @param  array<int, array{qty: float|int|string, rate: float|int|string}>  $items
     */
    public static function subtotal(array $items): float
    {
        return round(array_sum(array_map(fn (array $item) => $item['qty'] * $item['rate'], $items)), 2);
    }

    /**
     * المبلغ الثابت ما يتعداش مجموع المنتجات — نفس حساب ERPNext (Apply Discount On = Net Total).
     */
    public static function discount(?array $coupon, float $subtotal): float
    {
        if (! $coupon) {
            return 0.0;
        }

        $value = (float) ($coupon['discount_value'] ?? 0);
        $discount = ($coupon['discount_type'] ?? null) === 'percentage' ? $subtotal * $value / 100 : $value;

        return round(min(max($discount, 0), $subtotal), 2);
    }

    /**
     * بيرجّع {code, title, discount_type, discount_value}، أو null لو ERPNext مش متاح
     * حاليًا (عطل، مش رفض للكوبون) — المتصل يقرر يكمل ولا لا.
     *
     * @throws ValidationException الكوبون مرفوض — برسالة ERPNext على الحقل $field
     */
    public function validate(User $user, string $code, array $items, string $field = 'code'): ?array
    {
        if (static::hasPackage(array_column($items, 'item_code'))) {
            throw ValidationException::withMessages([$field => [self::BLOCKED_REASON]]);
        }

        if (! $user->erp_customer_id) {
            return null;
        }

        try {
            $result = $this->erp->callMethod('loyalpet.api.coupons.validate_coupon', [
                'erp_customer_id' => $user->erp_customer_id,
                'coupon_code' => $code,
                'items' => array_map(fn (array $item) => [
                    'item_code' => $item['item_code'],
                    'qty' => (float) $item['qty'],
                    'rate' => (float) $item['rate'],
                ], $items),
            ]);
        } catch (RequestException $e) {
            // frappe.throw بيرجع 417 — دي رسالة رفض للزبون (منتهي، خلص، استعملته قبل...)؛
            // أي حالة تانية عطل، فما نعرضش للزبون رسالة تقنية
            if ($e->response->status() === 417) {
                throw ValidationException::withMessages([
                    $field => [ERPNextService::errorMessage($e->response->json() ?? []) ?? 'الكوبون غير صالح'],
                ]);
            }

            Log::error('[Coupons] فشل التحقق من الكوبون: '.$e->getMessage());

            return null;
        } catch (ConnectionException $e) {
            Log::error('[Coupons] ERPNext مش متاح للتحقق من الكوبون: '.$e->getMessage());

            return null;
        }

        $data = $result['message'] ?? $result;

        if (! is_array($data) || ! in_array($data['discount_type'] ?? null, ['percentage', 'amount'], true)) {
            Log::error('[Coupons] رد غير متوقع من validate_coupon: '.json_encode($result));

            return null;
        }

        return [
            'code' => (string) ($data['coupon_code'] ?? $code),
            'title' => $data['title'] ?? null,
            'discount_type' => $data['discount_type'],
            'discount_value' => (float) ($data['discount_value'] ?? 0),
        ];
    }
}
