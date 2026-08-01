<?php

return [
    // رابط موقع ERPNext (Frappe Site)، بدون سلاش في الآخر — مثال: https://loyalpet.example.com
    'url' => env('ERPNEXT_URL'),

    // API Key/Secret على مستوى النظام — Laravel يحمل المفاتيح، ERPNext ما يعرفش عن مستخدمي الموبايل
    'api_key' => env('ERPNEXT_API_KEY'),
    'api_secret' => env('ERPNEXT_API_SECRET'),

    // نفس القيمة المستخدمة في webhook_secret وقت تسجيل الـ Webhook في ERPNext — للتحقق من التوقيع
    'webhook_secret' => env('ERPNEXT_WEBHOOK_SECRET'),
];
