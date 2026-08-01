<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'type'], 'unique_pref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
