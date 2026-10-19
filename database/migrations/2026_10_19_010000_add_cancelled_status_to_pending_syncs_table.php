<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_syncs', function (Blueprint $table) {
            // cancelled = الزبون لغى الطلب وهو لسه في الطابور (قبل ما يوصل ERPNext)
            $table->enum('status', [
                'pending',
                'processing',
                'done',
                'failed',
                'cancelled',
                'confirmed_by_erp',
                'waiting_user_confirmation',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('pending_syncs', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'processing',
                'done',
                'failed',
                'confirmed_by_erp',
                'waiting_user_confirmation',
            ])->default('pending')->change();
        });
    }
};
