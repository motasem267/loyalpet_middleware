<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->string('item_code', 100)->index(); // بدون foreign key حقيقي — زي باقي مراجع item_code في المشروع
            $table->decimal('qty', 15, 3)->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
    }
};
