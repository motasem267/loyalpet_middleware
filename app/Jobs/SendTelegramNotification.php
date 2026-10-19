<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * الإرسال دايمًا عبر الطابور — لو تليجرام واقع أو بطيء، ما يأخرش رد التطبيق ولا
 * يفشّل إنشاء/تعديل الطلب، والرسالة بتتعاد لوحدها.
 */
class SendTelegramNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 60;

    /**
     * @param  string|null  $photoPath  مسار على disk "local" (إيصال الحوالة) بيتبعت بعد النص
     * @param  bool  $deletePhoto  الإيصال المحلي بيتمسح بعد الإرسال (بقى في ERPNext خلاص)
     */
    public function __construct(
        public string $text,
        public ?string $photoPath = null,
        public bool $deletePhoto = false,
    ) {}

    public function handle(TelegramService $telegram): void
    {
        if (! $telegram->enabled()) {
            $this->cleanup();

            return;
        }

        $telegram->sendMessage($this->text);

        if ($this->photoPath && Storage::exists($this->photoPath)) {
            $telegram->sendPhoto(Storage::path($this->photoPath), '🧾 إيصال الحوالة');
        }

        $this->cleanup();
    }

    public function failed(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        if ($this->deletePhoto && $this->photoPath) {
            Storage::delete($this->photoPath);
        }
    }
}
