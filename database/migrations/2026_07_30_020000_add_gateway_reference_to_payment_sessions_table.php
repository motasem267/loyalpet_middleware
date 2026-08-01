<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table) {
            // "our_ref" الراجع من TLYNC — معرّف المعاملة الفريد من البوابة نفسها،
            // ده اللي بيتبعت لـ ERPNext كـ reference (مش idempotency_key بتاعنا إحنا)
            $table->string('gateway_reference')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table) {
            $table->dropColumn('gateway_reference');
        });
    }
};
