<?php

namespace App\Console\Commands;

use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('push:test {phone? : رقم هاتف الزبون — من غيره الإرسال لكل المستخدمين}')]
#[Description('يبعت إشعار Push تجريبي مباشرة (من غير الطابور) — للتأكد إن ربط Firebase شغال')]
class PushTest extends Command
{
    public function handle(FirebaseService $firebase): int
    {
        if (! $firebase->enabled()) {
            $this->error('ملف Firebase مش موجود أو مش مقروء: '.config('firebase.credentials'));

            return self::FAILURE;
        }

        $userIds = null;

        if ($phone = $this->argument('phone')) {
            $user = User::where('phone', $phone)->first();

            if (! $user) {
                $this->error("ما فيش مستخدم برقم {$phone}");

                return self::FAILURE;
            }

            $devices = DeviceToken::where('user_id', $user->id)->where('is_active', true)->count();
            $this->line("المستخدم: {$user->name} — أجهزة مسجلة: {$devices}");

            if (! $devices) {
                $this->warn('الزبون ما عندهوش جهاز مسجل — لازم يفتح التطبيق ويسجل دخول بعد ما يتضاف Firebase للتطبيق.');
            }

            $userIds = [$user->id];
        } elseif (! $this->confirm('الإرسال لكل المستخدمين؟')) {
            return self::SUCCESS;
        }

        SendPushNotification::dispatchSync('test', '🔔 إشعار تجريبي', 'لو وصلك هذا، الإشعارات شغالة ✅', $userIds);

        $this->info('تم الإرسال — شوف storage/logs/laravel.log لو ما وصلش.');

        return self::SUCCESS;
    }
}
