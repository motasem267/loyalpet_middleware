<?php

return [
    // iSend Libya — https://isend.com.ly (راجع API_ISEND_GUIDE.md)
    'base_url' => env('SMS_GATEWAY_URL', 'https://isend.com.ly/api/v1'),
    'api_key' => env('SMS_GATEWAY_API_KEY'),
    // لازم يكون sender name معتمد (APPROVED) فعليًا في حساب iSend، وإلا الإرسال هيترفض
    // (المعتمد حاليًا في الحساب: "ISEND" فقط — راجع GET /api/v1/sender-ids?status=APPROVED)
    'sender_name' => env('SMS_SENDER_NAME', 'ISEND'),
];
