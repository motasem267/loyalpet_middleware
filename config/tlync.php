<?php

return [
    // TEST افتراضيًا: https://uat-api.tlync.ly/api/v3 — للإنتاج غيّر TLYNC_BASE_URL بس
    'base_url' => env('TLYNC_BASE_URL', 'https://uat-api.tlync.ly/api/v3'),
    'store_id' => env('TLYNC_STORE_ID'),
    'api_token' => env('TLYNC_API_TOKEN'),

    // إيميل ثابت للشركة — TLYNC يطلبه كـ "إيميل العميل"، بنستخدمه موحّد لكل
    // المعاملات عشان يوصلنا تأكيد إضافي (طبقة تحقق ثالثة) بدل إيميل العميل الحقيقي
    // (مش عندنا إيميل عملاء في النظام أصلًا).
    'billing_email' => env('TLYNC_BILLING_EMAIL'),

    // الرابط اللي TLYNC يحوّل له العميل بعد إكمال/إلغاء الدفع (deep link للتطبيق مثلًا)
    'frontend_url' => env('TLYNC_FRONTEND_URL'),
];
