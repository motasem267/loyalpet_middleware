<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * قائمة إشعارات الزبون داخل التطبيق (شاشة الجرس) — كل Push بيتحفظ هنا كمان،
 * عشان لو فاته على الشاشة يلقاه.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $page = Notification::where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($perPage, ['id', 'type', 'title', 'body', 'data', 'is_read', 'read_at', 'created_at']);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'unread' => $this->unread($request),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->unread($request)]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        Notification::where('id', $notification)
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['unread' => $this->unread($request)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    private function unread(Request $request): int
    {
        return Notification::where('user_id', $request->user()->id)->where('is_read', false)->count();
    }
}
