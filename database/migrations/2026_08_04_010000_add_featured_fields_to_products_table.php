<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // منتج show_in_app = false ما يتعرضش في التطبيق خالص (أي endpoint)
            $table->boolean('show_in_app')->default(true)->after('is_active');
            $table->boolean('is_featured')->default(false)->after('show_in_app');
            $table->integer('featured_order')->default(0)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['show_in_app', 'is_featured', 'featured_order']);
        });
    }
};
