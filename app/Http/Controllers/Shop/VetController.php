<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\SyncToERPNext;
use App\Models\PendingSync;
use App\Services\ERPNextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetController extends Controller
{
    /**
     * حجز موعد بيطري — عبر الطابور زي الطلبات وتذاكر الدعم، مش نداء مباشر.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', 'string', 'max:150'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'string'],
            'doctor' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        $sync = PendingSync::create([
            'user_id' => $user->id,
            'action' => 'create',
            'doctype' => 'Vet Appointment',
            'payload' => $validated,
            'status' => 'pending',
            'priority' => 2,
        ]);

        SyncToERPNext::dispatch($sync);

        return response()->json([
            'message' => 'تم استلام طلب الموعد وجاري إرساله',
            'reference' => $sync->id,
        ], 202);
    }

    /**
     * قراءة مباشرة من ERPNext + أي موعد لسه في طور الإرسال.
     */
    public function index(Request $request, ERPNextService $erp): JsonResponse
    {
        $user = $request->user();

        $pending = PendingSync::where('user_id', $user->id)
            ->where('doctype', 'Vet Appointment')
            ->whereIn('status', ['pending', 'processing', 'failed'])
            ->get()
            ->map(fn (PendingSync $sync) => [
                'name' => null,
                'status' => $sync->status === 'failed' ? 'فشل الإرسال' : 'جاري الإرسال',
                'reference' => $sync->id,
            ]);

        $synced = collect();

        if ($user->erp_customer_id) {
            $result = $erp->callMethod('loyalpet.api.vet.get_vet_appointments', [
                'customer_id' => $user->erp_customer_id,
            ]);
            $synced = collect($result['message'] ?? $result);
        }

        return response()->json(['data' => $pending->concat($synced)->values()]);
    }
}
