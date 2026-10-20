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

    public function handle(FirebaseService $firebase): void
    {
        $notification = ['title' => $this->title, 'body' => $this->body, 'image' => $this->image];
        $data = [...$this->data, 'type' => $this->type];
        $status = 'disabled';
        $devicesByUser = collect();

        if ($firebase->enabled()) {
            if ($this->userIds === null) {
                $firebase->sendToTopic(config('firebase.all_users_topic'), $notification, $data);
                $status = 'sent';
            } else {
                $devices = DeviceToken::whereIn('user_id', $this->userIds)->where('is_active', true)->get(['user_id', 'token']);

                $result = $firebase->sendToTokens($devices->pluck('token')->all(), $notification, $data);

                if ($result['invalid']) {
                    DeviceToken::whereIn('token', $result['invalid'])->update(['is_active' => false]);
                }

                // الزبون اللي كل أجهزته طلعت ميتة يتسجل no_device مش sent
                $devicesByUser = $devices->whereNotIn('token', $result['invalid'])->groupBy('user_id');

                $status = 'sent';

                Log::info("[Push] {$this->type}: sent={$result['sent']} failed={$result['failed']} invalid=".count($result['invalid']));
            }
        }

        $this->saveToInbox($status, $devicesByUser);
    }

    private function saveToInbox(string $status, $devicesByUser): void
    {
        $users = User::query()
            ->when($this->userIds !== null, fn ($q) => $q->whereIn('id', $this->userIds))
            ->where('is_active', true)
            ->select('id');

        $now = now();
        $data = json_encode($this->data + ($this->image ? ['image' => $this->image] : []), JSON_UNESCAPED_UNICODE);

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
