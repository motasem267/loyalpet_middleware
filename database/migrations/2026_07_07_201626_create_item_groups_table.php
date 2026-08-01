<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_groups', function (Blueprint $table) {
            $table->id();
            $table->string('erp_name', 150)->unique();
            // بدون foreign key حقيقي — نفس منطق cart_items.erp_item_code:
            // الشجرة كاش يتزامن باستقلالية، ومربوطه بـ erp_name مش id داخلي
            $table->string('parent_erp_name', 150)->nullable()->index();
            $table->boolean('is_group')->default(false);
            // مسار الصورة زي ما راجع من حقل Item Group.image في ERPNext (نسبي، نبنيه رابط كامل وقت العرض)
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_groups');
    }
};
