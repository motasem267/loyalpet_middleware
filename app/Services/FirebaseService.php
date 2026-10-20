<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FCM HTTP v1 مباشرة (من غير kreait/firebase-php) — المكتبة محتاجة ext-sodium ومش
 * مضمونة على كل بيئة، والمطلوب منها هنا بسيط: توكن OAuth من الـ Service Account
 * (JWT موقّع RS256 بـ openssl) + إرسال + اشتراك في topic.
 */
class FirebaseService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function enabled(): bool
    {
        return is_readable((string) config('firebase.credentials'));
    }

    public function sendToTopic(string $topic, array $notification, array $data = []): void
    {
        Http::withToken($this->accessToken())
            ->post($this->sendUrl(), ['message' => $this->message(['topic' => $topic], $notification, $data)])
            ->throw();
    }

    /**
     * FCM v1 ما فيهوش multicast — طلب لكل جهاز، بالتوازي (Http::pool) على دفعات.
     *
     * @return array{sent: int, failed: int, invalid: string[]} invalid = توكنات ما عادش صالحة
     *                                                        (التطبيق اتمسح/اتسجل خروج) تتوقف
     */
    public function sendToTokens(array $tokens, array $notification, array $data = []): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'invalid' => []];
        $accessToken = $this->accessToken();

        foreach (array_chunk(array_values(array_unique($tokens)), 50) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $token) => $pool->as($token)
                    ->withToken($accessToken)
                    ->post($this->sendUrl(), ['message' => $this->message(['token' => $token], $notification, $data)]),
                $chunk
            ));

            foreach ($responses as $token => $response) {
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    $result['sent']++;

                    continue;
                }

                $result['failed']++;

                $error = $response instanceof \Illuminate\Http\Client\Response
                    ? ($response->json('error.details.0.errorCode') ?? $response->json('error.status'))
                    : null;

                if (in_array($error, ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'], true)) {
                    $result['invalid'][] = $token;
                }
            }
        }

        return $result;
    }

    /**
     * Instance ID API — لسه هي الطريقة الرسمية للاشتراك في topic من السيرفر
     * (بتقبل توكن OAuth مع هيدر access_token_auth).
     */
    public function subscribeToTopic(array $tokens, string $topic): void
    {
        $this->topicBatch('batchAdd', $tokens, $topic);
    }

    public function unsubscribeFromTopic(array $tokens, string $topic): void
    {
        $this->topicBatch('batchRemove', $tokens, $topic);
    }

    private function topicBatch(string $action, array $tokens, string $topic): void
    {
        foreach (array_chunk(array_values($tokens), 1000) as $chunk) {
            Http::withToken($this->accessToken())
                ->withHeaders(['access_token_auth' => 'true'])
                ->post("https://iid.googleapis.com/iid/v1:{$action}", [
                    'to' => '/topics/'.$topic,
                    'registration_tokens' => $chunk,
                ])
                ->throw();
        }
    }

    /**
     * data في FCM لازم تكون strings بس. android/apns: أولوية عالية + صوت، عشان الإشعار
     * يطلع على الشاشة حتى والتطبيق مسكّر.
     */
    private function message(array $target, array $notification, array $data): array
    {
        return [
            ...$target,
            'notification' => array_filter([
                'title' => $notification['title'],
                'body' => $notification['body'],
                'image' => $notification['image'] ?? null,
            ]),
            'data' => array_map('strval', array_filter($data, fn ($v) => $v !== null)),
            'android' => [
                'priority' => 'high',
                'notification' => ['sound' => 'default'],
            ],
            'apns' => [
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ];
    }

    private function sendUrl(): string
    {
        return 'https://fcm.googleapis.com/v1/projects/'.$this->credentials()['project_id'].'/messages:send';
    }

    /**
     * توكن OAuth صالح ساعة — بنخزّنه 50 دقيقة بدل ما نطلب واحد مع كل إشعار.
     */
    private function accessToken(): string
    {
        return Cache::remember('firebase.access_token', now()->addMinutes(50), function () {
            $credentials = $this->credentials();
            $now = time();

            $jwt = $this->jwt([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key']);

            return Http::asForm()->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }

    private function jwt(array $claims, string $privateKey): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('فشل توقيع JWT لـ Firebase — راجع private_key في ملف الـ Service Account');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function credentials(): array
    {
        $path = (string) config('firebase.credentials');

        if (! is_readable($path)) {
            throw new RuntimeException("ملف Firebase مش موجود أو مش مقروء: {$path}");
        }

        return json_decode(file_get_contents($path), true);
    }
}
