<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\SyncToERPNext;
use App\Models\PendingSync;
use App\Services\ERPNextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    /**
     * إنشاء حجز فندقة/إيواء — عبر الطابور زي الطلبات وتذاكر الدعم، مش نداء مباشر.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room' => ['required', 'string', 'max:150'],
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'payment_method' => ['required', 'string', 'in:wallet,cash_on_delivery'],
            'services' => ['nullable', 'array'],
            'services.*.service' => ['required_with:services', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        $sync = PendingSync::create([
            'user_id' => $user->id,
            'action' => 'create',
            'doctype' => 'Hotel Booking',
            'payload' => $validated,
            'status' => 'pending',
            'priority' => 2,
        ]);

        SyncToERPNext::dispatch($sync);

        return response()->json([
            'message' => 'تم استلام طلب الحجز وجاري إرساله',
            'reference' => $sync->id,
        ], 202);
    }

    /**
     * قراءة مباشرة من ERPNext + أي حجز لسه في طور الإرسال.
     */
    public function index(Request $request, ERPNextService $erp): JsonResponse
    {
        $user = $request->user();

        $pending = PendingSync::where('user_id', $user->id)
            ->where('doctype', 'Hotel Booking')
            ->whereIn('status', ['pending', 'processing', 'failed'])
            ->get()
            ->map(fn (PendingSync $sync) => [
                'name' => null,
                'status' => $sync->status === 'failed' ? 'فشل الإرسال' : 'جاري الإرسال',
                'reference' => $sync->id,
            ]);

        $synced = collect();

        if ($user->erp_customer_id) {
            $result = $erp->callMethod('loyalpet.api.hotel.get_hotel_bookings', [
                'customer_id' => $user->erp_customer_id,
            ]);
            $synced = collect($result['message'] ?? $result);
        }

        return response()->json(['data' => $pending->concat($synced)->values()]);
    }
}
