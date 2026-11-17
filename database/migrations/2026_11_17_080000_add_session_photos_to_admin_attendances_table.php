<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_attendances', function (Blueprint $table): void {
            $table->string('morning_photo_one_path')->nullable();
            $table->string('morning_photo_two_path')->nullable();
            $table->dateTime('morning_photos_uploaded_at')->nullable();
            $table->string('afternoon_photo_one_path')->nullable();
            $table->string('afternoon_photo_two_path')->nullable();
            $table->dateTime('afternoon_photos_uploaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_attendances', function (Blueprint $table): void {
            $table->dropColumn([
                'morning_photo_one_path',
                'morning_photo_two_path',
                'morning_photos_uploaded_at',
                'afternoon_photo_one_path',
                'afternoon_photo_two_path',
                'afternoon_photos_uploaded_at',
            ]);
        });
    }
};
