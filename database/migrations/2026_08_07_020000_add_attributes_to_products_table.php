<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // خصائص المتغيّر (Item Variant Attribute) — [{"attribute":"الوزن","attribute_value":"كيلو"}, ...]
            // فاضي ([]) للمنتجات العادية اللي مالهاش variants
            $table->json('attributes')->nullable()->after('variant_of');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('attributes');
        });
    }
};
