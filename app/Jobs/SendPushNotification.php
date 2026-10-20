<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * إرسال Push + حفظه في قائمة إشعارات الزبون داخل التطبيق (notifications).
 * الإرسال الأول وبعدين الحفظ — لو FCM فشل والجوب اتعاد، ما يتكررش الإشعار في القائمة.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  int[]|null  $userIds  null = كل المستخدمين (topic واحد)
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public ?array $userIds = null,
        public array $data = [],
        public ?string $image = null,
    ) {}

    /**
     * @return array{sent: int, failed: int} للـ topic (الكل) FCM ما بيرجعش عدد لكل جهاز —
     *                                       sent = عدد الأجهزة النشطة المشتركة
     */
    public function handle(FirebaseService $firebase): array
    {
        $notification = ['title' => $this->title, 'body' => $this->body, 'image' => $this->image];
        $data = $this->appData();
        $status = 'disabled';
        $devicesByUser = collect();
        $counts = ['sent' => 0, 'failed' => 0];

        if ($firebase->enabled()) {
            if ($this->userIds === null) {
                $firebase->sendToTopic(config('firebase.all_users_topic'), $notification, $data);
                $status = 'sent';
                $counts['sent'] = DeviceToken::where('is_active', true)->count();
            } else {
                $devices = DeviceToken::whereIn('user_id', $this->userIds)->where('is_active', true)->get(['user_id', 'token']);

                $result = $firebase->sendToTokens($devices->pluck('token')->all(), $notification, $data);

                if ($result['invalid']) {
                    DeviceToken::whereIn('token', $result['invalid'])->update(['is_active' => false]);
                }

                // الزبون اللي كل أجهزته طلعت ميتة يتسجل no_device مش sent
                $devicesByUser = $devices->whereNotIn('token', $result['invalid'])->groupBy('user_id');

                $status = 'sent';
                $counts = ['sent' => $result['sent'], 'failed' => $result['failed']];

                Log::info("[Push] {$this->type}: sent={$result['sent']} failed={$result['failed']} invalid=".count($result['invalid']));
            }
        }

        $this->saveToInbox($status, $devicesByUser);

        return $counts;
    }

    /**
     * "type" في data = الشاشة اللي التطبيق يفتحها لما الزبون يدوس (عقد التطبيق):
     * order → طلباتي، product (+ product_id) → تفاصيل المنتج، أي حاجة تانية → شاشة
     * الإشعارات. نوع الإشعار نفسه (order_status, broadcast...) بيتبعت في "category".
     * نفس البيانات بتتحفظ في قائمة الإشعارات، عشان الضغط من القائمة يفتح نفس الوجهة.
     */
    private function appData(): array
    {
        $type = match (true) {
            ! empty($this->data['product_id']) => 'product',
            $this->type === 'order_status' => 'order',
            ($this->data['action'] ?? null) === 'item_group' => 'item_group',
            ($this->data['action'] ?? null) === 'url' => 'url',
            default => 'general',
        };

        return [...$this->data, 'type' => $type, 'category' => $this->type];
    }

    private function saveToInbox(string $status, $devicesByUser): void
    {
        $users = User::query()
            ->when($this->userIds !== null, fn ($q) => $q->whereIn('id', $this->userIds))
            ->where('is_active', true)
            ->select('id');

        $now = now();
        $data = json_encode($this->appData() + ($this->image ? ['image' => $this->image] : []), JSON_UNESCAPED_UNICODE);

        $users->chunkById(1000, function ($chunk) use ($status, $devicesByUser, $now, $data) {
            DB::table('notifications')->insert($chunk->map(fn ($user) => [
                'user_id' => $user->id,
                'type' => $this->type,
                'title' => $this->title,
                'body' => $this->body,
                'data' => $data,
                'fcm_status' => $this->userIds === null || $status !== 'sent'
                    ? $status
                    : ($devicesByUser->has($user->id) ? 'sent' : 'no_device'),
                'sent_at' => $status === 'sent' ? $now : null,
                'created_at' => $now,
            ])->all());
        });
    }
}
