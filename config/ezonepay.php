<?php

return [
    'base_url' => env('EZONEPAY_BASE_URL', 'https://api.ezonepay.com'),

    // X-API-Key — لازم يكون عنده scope: payment.link.create (وpayment.link.view لو حبينا نستعلم لاحقًا)
    'api_key' => env('EZONEPAY_API_KEY'),

    // بيتحدد بعد تشغيل ezonepay:subscribe-webhook مرة واحدة (SecretKey الراجع من
    // POST /Webhook/subscribe) — يُستخدم للتحقق من توقيع X-Signature في الويبهوك
    'webhook_secret' => env('EZONEPAY_WEBHOOK_SECRET'),
];
