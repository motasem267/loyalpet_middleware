<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Jobs\SyncToERPNext;
use App\Models\PendingSync;
use App\Services\ERPNextService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    /**
     * فتح تذكرة دعم فني جديدة — عبر الطابور زي أي كتابة تجاه ERPNext.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $sync = PendingSync::create([
            'user_id' => $request->user()->id,
            'action' => 'create',
            'doctype' => 'Issue',
            'payload' => $validated,
            'status' => 'pending',
            'priority' => 3,
        ]);

        SyncToERPNext::dispatch($sync);

        return response()->json([
            'message' => 'تم استلام رسالتك، هيتم الرد عليك قريبًا',
            'reference' => $sync->id,
        ], 202);
    }

    /**
     * قراءة مباشرة من ERPNext + أي تذكرة لسه في طور الإرسال.
     */
    public function index(Request $request, ERPNextService $erp): JsonResponse
    {
        $user = $request->user();

        $pending = PendingSync::where('user_id', $user->id)
            ->where('doctype', 'Issue')
            ->whereIn('status', ['pending', 'processing', 'failed'])
            ->get()
            ->map(fn (PendingSync $sync) => [
                'name' => null,
                'subject' => $sync->payload['subject'] ?? null,
                'status' => $sync->status === 'failed' ? 'فشل الإرسال' : 'جاري الإرسال',
                'opening_date' => $sync->created_at,
                'reference' => $sync->id,
            ]);

        $synced = $user->erp_customer_id
            ? $erp->getList('Issue',
                filters: [['customer', '=', $user->erp_customer_id]],
                fields: ['name', 'subject', 'status', 'opening_date'],
                limit: 50,
            )
            : [];

        return response()->json(['data' => $pending->concat($synced)->values()]);
    }

    public function show(Request $request, string $ticket, ERPNextService $erp): JsonResponse
    {
        try {
            $doc = $erp->get('Issue', $ticket);
        } catch (RequestException) {
            abort(404, 'التذكرة غير موجودة');
        }

        abort_unless(($doc['customer'] ?? null) === $request->user()->erp_customer_id, 403);

        return response()->json(['data' => $doc]);
    }
}
