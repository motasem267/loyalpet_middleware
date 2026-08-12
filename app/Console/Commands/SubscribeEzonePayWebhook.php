<?php

namespace App\Console\Commands;

use App\Services\EzonePayService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ezonepay:subscribe-webhook')]
#[Description('تسجيل رابط استقبال ويبهوك Ezone Pay عند Ezone Pay — مرة واحدة بس وقت الإعداد')]
class SubscribeEzonePayWebhook extends Command
{
    public function handle(EzonePayService $ezonePay): int
    {
        $url = url('/api/v1/webhooks/ezonepay');

        $response = $ezonePay->subscribeWebhook($url);

        // الرد راجع بـ camelCase (id, event, url, secretKey...) مش PascalCase زي
        // جداول التوثيق — تأكدنا منها فعليًا بتجربة حقيقية.
        $this->info('تم تسجيل الويبهوك بنجاح.');
        $this->line('Url: '.($response['url'] ?? $url));
        $this->line('Event: '.($response['event'] ?? '2/Paid'));
        $this->newLine();
        $this->warn('⚠️ ضيف السطر ده في .env ثم شغّل config:clear && config:cache:');
        $this->line('EZONEPAY_WEBHOOK_SECRET='.($response['secretKey'] ?? '??? — secretKey مش موجود في الرد، راجع الاستجابة كاملة'));

        return self::SUCCESS;
    }
}
