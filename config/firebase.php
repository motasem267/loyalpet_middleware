<?php

return [
    // ملف Service Account من Firebase (Project settings → Service accounts) — سري، برّه git
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase-credentials.json')),

    // كل جهاز بيتسجل بيشترك في الـ topic ده — الإرسال "للكل" رسالة وحدة بدل لفّة على كل الأجهزة
    'all_users_topic' => env('FIREBASE_ALL_USERS_TOPIC', 'all_users'),
];
