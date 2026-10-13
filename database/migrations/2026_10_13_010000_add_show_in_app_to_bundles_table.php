<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            // من الحقل المخصص custom_show_in_app على Product Bundle — الحزم اللي مستخدمة
            // للمخزون بس (وزن بيخصم من صنف سايب) بتتطفى عشان ما تظهرش كعرض في التطبيق
            $table->boolean('show_in_app')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('show_in_app');
        });
    }
};
