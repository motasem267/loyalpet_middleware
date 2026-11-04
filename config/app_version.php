<?php

return [
    // minimum: أقل نسخة مسموحة — أي نسخة أقدم بيترفض طلبها بـ 426 (تحديث إجباري).
    // latest: آخر نسخة منشورة — أقدم منها = تحديث اختياري (update_available).
    // فاضي = بلا حد. الصيغة زي ما التطبيق بيبعتها: 1.2.0
    'platforms' => [
        'android' => [
            'minimum' => env('APP_MIN_VERSION_ANDROID'),
            'latest' => env('APP_LATEST_VERSION_ANDROID'),
            'store_url' => env('APP_STORE_URL_ANDROID', 'https://play.google.com/store/apps/details?id=com.loyal_pet.app'),
        ],
        'ios' => [
            'minimum' => env('APP_MIN_VERSION_IOS'),
            'latest' => env('APP_LATEST_VERSION_IOS'),
            'store_url' => env('APP_STORE_URL_IOS'),
        ],
    ],

    // النسخ القديمة اللي ما بتبعتش X-App-Version خالص — false لحد ما أغلب الزبائن
    // يحدّثوا لنسخة بتبعته، وإلا كل النسخ الحالية هتتقفل مرة وحدة
    'block_missing_version' => (bool) env('APP_BLOCK_MISSING_VERSION', false),

    'message' => env('APP_UPDATE_MESSAGE', 'يوجد تحديث جديد للتطبيق، يرجى التحديث للاستمرار'),
];
