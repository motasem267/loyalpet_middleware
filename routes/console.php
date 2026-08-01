<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// احتياطي فقط — العمليات الجديدة بتتبعت فورًا للـ Queue وقت إنشائها، الأمر ده بيلتقط
// أي حاجة عالقة (قطع إنترنت وقت الإنشاء، أو فشلت وعندها محاولات متبقية)
Schedule::command('sync:process')->everyMinute();

// بديل أبسط من Supervisor: يفرّغ أي jobs عالقة في الطابور كل دقيقة بدل ما يحتاج
// process دائم شغّال طول الوقت — تأخير أقصاه دقيقة، مقابل صفر إعداد إضافي على السيرفر.
Schedule::command('queue:work --stop-when-empty --tries=3')->everyMinute()->withoutOverlapping();
