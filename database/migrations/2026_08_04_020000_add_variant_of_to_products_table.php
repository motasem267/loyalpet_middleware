<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // erp_name بتاع صنف "القالب" (Template Item) في نظام Item Variants بتاع
            // ERPNext — بيربط العبوات المختلفة (نص كيلو / كيلو / زوز كيلو) لنفس المنتج
            $table->string('variant_of', 150)->nullable()->index()->after('item_group_erp_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('variant_of');
        });
    }
};
