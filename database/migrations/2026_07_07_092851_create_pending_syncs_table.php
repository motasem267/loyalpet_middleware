<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('action', ['create', 'update', 'delete']);
            $table->string('doctype', 100);
            $table->string('erp_name', 150)->nullable();
            $table->json('payload');
            $table->enum('status', [
                'pending',
                'processing',
                'done',
                'failed',
                'confirmed_by_erp',
                'waiting_user_confirmation',
            ])->default('pending');
            $table->tinyInteger('priority')->default(2);
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_syncs');
    }
};
