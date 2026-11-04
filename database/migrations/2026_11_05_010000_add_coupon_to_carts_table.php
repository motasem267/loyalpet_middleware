<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // الكوبون المطبّق على السلة — coupon = {code, title, discount_type, discount_value}
            // زي ما رجعها ERPNext وقت التحقق، عشان الخصم يتحسب محليًا مع كل عرض للسلة
            $table->string('coupon_code', 64)->nullable()->after('status');
            $table->json('coupon')->nullable()->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon']);
        });
    }
};
