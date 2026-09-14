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
            $table->string('phone');

            // Menyimpan list barang dalam bentuk JSON
            $table->json('items')->nullable();

            $table->string('color')->nullable();

            // Tanggal gambar dikirim melalui WhatsApp
            $table->date('image_date')->nullable();

            // File gambar
            $table->string('image')->nullable();

            // Deadline order
            $table->date('deadline')->nullable();

            // 0 = normal, 1 = urgent
            $table->boolean('urgent')->default(false);

            $table->enum('status', [
                'Pending',
                'Design',
                'Production',
                'Finishing',
                'Ready',
                'Completed',
                'Cancelled'
            ])->default('Pending');

            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};