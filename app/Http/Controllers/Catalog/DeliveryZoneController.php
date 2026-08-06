<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use Illuminate\Http\JsonResponse;

class DeliveryZoneController extends Controller
{
    /**
     * مناطق التوصيل النشطة — من الكاش المحلي (بيتحدّث فورًا عبر الويبهوك،
     * مش نداء مباشر لـ ERPNext في كل طلب).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DeliveryZone::where('is_active', true)
                ->orderBy('zone_name')
                ->get(['erp_name', 'zone_name', 'delivery_price']),
        ]);
    }
}
