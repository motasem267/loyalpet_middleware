<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TelegramService
{
    public function enabled(): bool
    {
        return config('telegram.bot_token') && config('telegram.chat_ids');
    }

    /**
     * disable_notification = false صراحةً — الرسالة بتوصل بإشعار وصوت على الموبايل
     * (ده الافتراضي في تليجرام أصلًا، بس محطوط عشان ما يتغيرش بالغلط).
     *
     * HTML بدل Markdown — أسماء الأصناف والعناوين فيها رموز (* _ -) بتكسر Markdown،
     * وفي HTML بنهرّب < > & بس (escape()).
     */
    public function sendMessage(string $text): void
    {
        foreach (config('telegram.chat_ids') as $chatId) {
            $this->client()->post('/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'disable_notification' => false,
            ])->throw();
        }
    }

    /**
     * caption في تليجرام حدّه 1024 حرف — الرسالة الطويلة بتتبعت لوحدها والصورة بعدها.
     */
    public function sendPhoto(string $path, string $caption = ''): void
    {
        foreach (config('telegram.chat_ids') as $chatId) {
            $this->client()
                ->attach('photo', file_get_contents($path), basename($path))
                ->post('/sendPhoto', [
                    'chat_id' => $chatId,
                    'caption' => mb_substr($caption, 0, 1024),
                    'parse_mode' => 'HTML',
                    'disable_notification' => 'false',
                ])->throw();
        }
    }

    public function getUpdates(): array
    {
        return $this->client()->get('/getUpdates')->throw()->json('result') ?? [];
    }

    public static function escape(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.config('telegram.bot_token'))->timeout(15);
    }
}
