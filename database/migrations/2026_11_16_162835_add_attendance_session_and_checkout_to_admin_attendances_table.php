<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admin_attendances', function (Blueprint $table) {
            $table->string('attendance_session')->nullable()->after('attendance_date');
            $table->dateTime('checked_out_at')->nullable()->after('attendance_session');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_attendances', function (Blueprint $table) {
            $table->dropColumn(['attendance_session', 'checked_out_at']);
        });
    }
};
