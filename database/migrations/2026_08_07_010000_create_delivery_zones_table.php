<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('erp_name', 150)->unique(); // اسم سجل Delivery Zone في ERPNext (مثل DZ-0001)
            $table->string('zone_name');
            $table->decimal('delivery_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
    }
};
