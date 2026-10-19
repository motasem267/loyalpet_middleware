<?php

return [
    // طرق الدفع المتاحة وقت إنشاء/تعديل الطلب — المفتاح هو القيمة اللي بتتبعت لـ ERPNext
    // (custom_payment_method)، والقيمة الاسم اللي بيظهر للزبون في التطبيق
    'methods' => [
        'wallet' => 'المحفظة',
        'cash_on_delivery' => 'نقداً عند الاستلام',
        'card_on_delivery' => 'بطاقة مصرفية عند الاستلام',
        'bank_transfer' => 'حوالة مصرفية',
    ],

    // الحساب اللي الزبون بيحوّل عليه في طريقة "حوالة مصرفية" — بيظهر في التطبيق
    'bank' => [
        'bank_name' => env('BANK_NAME'),
        'account_name' => env('BANK_ACCOUNT_NAME'),
        'account_number' => env('BANK_ACCOUNT_NUMBER'),
        'iban' => env('BANK_IBAN'),
    ],
];
