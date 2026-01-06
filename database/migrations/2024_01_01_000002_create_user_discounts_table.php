<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('discount_id')->index();
            $table->integer('times_used')->default(0);
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unique(['user_id', 'discount_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_discounts');
    }
};
