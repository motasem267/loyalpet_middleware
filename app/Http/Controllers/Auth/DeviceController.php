<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\FirebaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    /**
     * التوكن unique — لو نفس الجهاز اتسجل بحساب تاني، بيتنقل للحساب الجديد.
     * الاشتراك في topic "كل المستخدمين" هنا، عشان الإرسال للكل يبقى رسالة وحدة.
     */
    public function register(Request $request, FirebaseService $firebase): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', 'in:android,ios'],
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $validated['fcm_token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'is_active' => true,
            ]
        );

        $this->topic($firebase, 'subscribeToTopic', $validated['fcm_token']);

        return response()->json(['status' => 'registered']);
    }

    /**
     * وقت تسجيل الخروج — من غيره الجهاز يفضل يستقبل إشعارات الحساب ده (وإشعارات "الكل").
     */
    public function unregister(Request $request, FirebaseService $firebase): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::where('token', $validated['fcm_token'])
            ->where('user_id', $request->user()->id)
            ->update(['is_active' => false]);

        $this->topic($firebase, 'unsubscribeFromTopic', $validated['fcm_token']);

        return response()->json(['status' => 'unregistered']);
    }

    /**
     * فشل الاشتراك ما يفشّلش تسجيل الجهاز — الإرسال لزبائن محددين بيمشي بالتوكن مباشرة.
     */
    private function topic(FirebaseService $firebase, string $method, string $token): void
    {
        if (! $firebase->enabled()) {
            return;
        }

        try {
            $firebase->{$method}([$token], config('firebase.all_users_topic'));
        } catch (\Throwable $e) {
            Log::warning("[Push] {$method} فشل: ".$e->getMessage());
        }
    }
}
