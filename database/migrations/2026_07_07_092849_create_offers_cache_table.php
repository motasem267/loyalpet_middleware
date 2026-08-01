<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers_cache', function (Blueprint $table) {
            $table->id();
            $table->string('erp_name', 150)->unique();
            $table->string('title');
            $table->enum('discount_type', ['percentage', 'amount']);
            $table->decimal('discount_value', 15, 2);
            $table->string('coupon_code', 50)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_upto')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers_cache');
    }
};
