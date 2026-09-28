<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_id')->unique();

            $table->string('guest_name');
            $table->string('phone')->nullable();

            $table->decimal('total_order', 15, 2)->default(0);

            $table->date('deadline')->nullable();

            $table->dateTime('last_edit')->nullable();

            $table->string('status')->default('None');

            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};