<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->string('erp_name', 150)->unique(); // اسم سجل Product Bundle في ERPNext
            $table->string('item_code', 100)->nullable(); // new_item_code — الصنف اللي بيمثّل الباقة عند البيع
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->string('image_path')->nullable(); // من الحقل المخصص custom_image على Product Bundle
            $table->boolean('is_active')->default(true);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundles');
    }
};
