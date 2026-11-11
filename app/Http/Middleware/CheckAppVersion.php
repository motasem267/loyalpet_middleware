<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * التطبيق بيبعت مع كل طلب: X-App-Version (مثلًا 1.2.0) و X-App-Platform (android|ios).
 * - بنسجّل النسخة على الزبون (users.app_version) لو داخل بحسابه.
 * - لو النسخة أقدم من الحد الأدنى للمنصة → 426 Upgrade Required، والتطبيق يعرض شاشة التحديث.
 * الويبهوكس (ERPNext/Ezone Pay) وفحص النسخة نفسه مستثنيين.
 */
class CheckAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/webhooks/*', 'api/v1/app/version')) {
            return $next($request);
        }

        $version = trim((string) $request->header('X-App-Version'));
        $platform = strtolower(trim((string) $request->header('X-App-Platform')));

        if ($version !== '') {
            $this->remember($request, $version, $platform);
        }

        if (static::updateRequired($version, $platform)) {
            return response()->json([
                'message' => config('app_version.message'),
                'update_required' => true,
                'store_url' => config("app_version.platforms.{$platform}.store_url"),
            ], 426);
        }

        return $next($request);
    }

    public static function updateRequired(string $version, string $platform): bool
    {
        $version = static::normalize($version);

        if ($version === '') {
            return (bool) config('app_version.block_missing_version');
        }

        $minimum = config('app_version.platforms.'.strtolower(trim($platform)).'.minimum');

        return $minimum && version_compare($version, static::normalize($minimum), '<');
    }

    /**
     * الأرقام بس، مكمّلة لـ 3 خانات: "1.0" و"v1.0.0" و"1.0.0 (5)" و"1.0.0+5" كلها 1.0.0 —
     * من غيرها version_compare بيعتبر "1.0" أقدم من "1.0.0" ويقفل التطبيق على نسخة سليمة.
     * نص من غير أرقام = كأنه ما اتبعتش.
     */
    public static function normalize(string $version): string
    {
        if (! preg_match('/\d+(?:\.\d+)*/', $version, $match)) {
            return '';
        }

        return implode('.', array_pad(explode('.', $match[0]), 3, '0'));
    }

    /**
     * بنكتب بس لو النسخة/المنصة اتغيرت أو آخر تسجيل أقدم من يوم — مش مع كل طلب.
     */
    private function remember(Request $request, string $version, string $platform): void
    {
        $user = $request->user('sanctum');

        if (! $user) {
            return;
        }

        $platform = in_array($platform, ['android', 'ios'], true) ? $platform : null;

        if ($user->app_version === $version && $user->app_platform === $platform
            && $user->app_version_seen_at?->gt(now()->subDay())) {
            return;
        }

        $user->forceFill([
            'app_version' => mb_substr($version, 0, 20),
            'app_platform' => $platform,
            'app_version_seen_at' => now(),
        ])->saveQuietly();
    }
}
