<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // آخر نسخة تطبيق استخدمها الزبون (هيدر X-App-Version) — للتحديث الإجباري والإحصائيات
            $table->string('app_version', 20)->nullable()->index();
            $table->string('app_platform', 10)->nullable();
            $table->timestamp('app_version_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'app_platform', 'app_version_seen_at']);
        });
    }
};
