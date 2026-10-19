<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:test')]
#[Description('يبعت رسالة تجريبية لمحادثات TELEGRAM_CHAT_IDS مباشرة (من غير الطابور)')]
class TelegramTest extends Command
{
    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->enabled()) {
            $this->error('TELEGRAM_BOT_TOKEN أو TELEGRAM_CHAT_IDS مش مضبوطين في .env (وبعدها config:cache)');

            return self::FAILURE;
        }

        $telegram->sendMessage("✅ <b>بوت طلبات LoyalPet شغال</b>\nأي طلب جديد أو تعديل أو تغيير حالة هيوصل هنا.");

        $this->info('تم الإرسال لـ '.count(config('telegram.chat_ids')).' محادثة.');

        return self::SUCCESS;
    }
}
