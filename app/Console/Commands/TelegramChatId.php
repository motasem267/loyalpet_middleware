<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:chat-id')]
#[Description('يعرض أرقام المحادثات/القروبات اللي البوت وصلته منها رسائل — لضبط TELEGRAM_CHAT_IDS')]
class TelegramChatId extends Command
{
    public function handle(TelegramService $telegram): int
    {
        if (! config('telegram.bot_token')) {
            $this->error('TELEGRAM_BOT_TOKEN مش مضبوط في .env');

            return self::FAILURE;
        }

        $chats = [];

        foreach ($telegram->getUpdates() as $update) {
            foreach (['message', 'my_chat_member', 'channel_post', 'edited_message'] as $key) {
                if ($chat = $update[$key]['chat'] ?? null) {
                    $chats[$chat['id']] = [$chat['id'], $chat['type'], $chat['title'] ?? $chat['first_name'] ?? '-'];
                }
            }
        }

        if (! $chats) {
            // البوت في القروب ما بيشوفش الرسائل العادية (privacy mode) — بس الأوامر
            $this->warn('ما فيش رسائل للبوت لسه. ابعت في القروب: /start@LoyalPet_Orders_bot ثم أعد تشغيل الأمر.');

            return self::FAILURE;
        }

        $this->table(['Chat ID', 'النوع', 'الاسم'], array_values($chats));
        $this->line('حط رقم القروب (السالب) في .env: TELEGRAM_CHAT_IDS=<الرقم> ثم config:cache');

        return self::SUCCESS;
    }
}
