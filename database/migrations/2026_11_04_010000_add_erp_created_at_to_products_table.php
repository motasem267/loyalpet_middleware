<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // تاريخ إنشاء الصنف في ERPNext (حقل creation) — لترتيب "المضافة حديثًا".
            // مش created_at محلي، لأن المزامنة الكاملة بتعيد إنشاء الصفوف بتاريخ النهارده
            $table->timestamp('erp_created_at')->nullable()->index()->after('featured_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('erp_created_at');
        });
    }
};
