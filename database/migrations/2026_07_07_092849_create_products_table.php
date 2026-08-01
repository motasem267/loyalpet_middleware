<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('erp_name', 150)->unique();
            $table->string('item_code', 100)->nullable();
            $table->string('item_name');
            // اسم أقرب Item Group (leaf) في ERPNext — الشجرة الكاملة والمسار يتحسبوا من جدول item_groups
            $table->string('item_group_erp_name', 150)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
