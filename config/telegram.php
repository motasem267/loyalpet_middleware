<?php

return [
    // من @BotFather — فاضي = إشعارات التليجرام مطفية (من غير ما يأثر على الطلبات)
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // رقم قروب (سالب، زي -1001234567890) أو أكتر من محادثة مفصولين بفاصلة —
    // يتجاب بـ php artisan telegram:chat-id بعد ما حد يبعت رسالة للبوت
    'chat_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_CHAT_IDS', ''))))),
];
