<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتيجة عملية الدفع</title>
    @if ($session && $session->status === 'pending')
        <meta http-equiv="refresh" content="3">
    @endif
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f7;
            font-family: -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 32px 28px;
            max-width: 360px;
            width: 90%;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        .icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #fff;
        }
        .icon.success { background: #22c55e; }
        .icon.failed { background: #ef4444; }
        .icon.pending { background: #f59e0b; }
        .icon.unknown { background: #9ca3af; }
        h1 { font-size: 20px; margin: 0 0 8px; color: #111827; }
        p.desc { color: #6b7280; font-size: 14px; margin: 0 0 20px; }
        .amount { font-size: 28px; font-weight: bold; color: #111827; margin: 0 0 24px; }
        .amount span { font-size: 15px; color: #6b7280; font-weight: normal; }
        button.close-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #111827;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
        }
        button.close-btn:active { opacity: 0.85; }
    </style>
</head>
<body>
    <div class="card">
        @if (! $session)
            <div class="icon unknown">؟</div>
            <h1>لم يتم العثور على العملية</h1>
            <p class="desc">تعذر إيجاد بيانات عملية الدفع هذه.</p>
        @elseif ($session->status === 'paid')
            <div class="icon success">✓</div>
            <h1>تم الدفع بنجاح</h1>
            <p class="desc">تم شحن محفظتك بالمبلغ التالي</p>
            <div class="amount">{{ number_format((float) $session->amount, 2) }} <span>د.ل</span></div>
        @elseif ($session->status === 'failed')
            <div class="icon failed">✕</div>
            <h1>فشلت عملية الدفع</h1>
            <p class="desc">لم تتم عملية الدفع، حاول مرة أخرى من التطبيق.</p>
        @elseif ($session->status === 'expired')
            <div class="icon failed">✕</div>
            <h1>انتهت صلاحية العملية</h1>
            <p class="desc">حاول إنشاء عملية شحن جديدة من التطبيق.</p>
        @else
            <div class="icon pending">⏳</div>
            <h1>جاري تأكيد عملية الدفع</h1>
            <p class="desc">يرجى الانتظار قليلًا، هذه الصفحة ستتحدث تلقائيًا...</p>
            <div class="amount">{{ number_format((float) $session->amount, 2) }} <span>د.ل</span></div>
        @endif

        <button class="close-btn" onclick="closeAndReturn()">إغلاق والعودة للتطبيق</button>
    </div>

    <script>
        function closeAndReturn() {
            // نحاول أكتر من طريقة — التطبيق ممكن يراقب تغيّر الرابط بدل ما يعتمد
            // على الزر أصلًا، فهذا بس احتياطي.
            try { window.close(); } catch (e) {}
            window.location.href = 'loyalpet://payment-complete';
        }
    </script>
</body>
</html>
