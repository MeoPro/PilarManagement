<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('guest_id')
                ->nullable()
                ->after('id')
                ->constrained('guests')
                ->nullOnDelete();
        });

        $guestIdsByName = [];
        $duplicateGuestIds = [];

        foreach (DB::table('guests')->orderBy('id')->get() as $guest) {
            $nameKey = mb_strtolower(trim($guest->name));

            if (! isset($guestIdsByName[$nameKey])) {
                $guestIdsByName[$nameKey] = $guest->id;
            } else {
                $duplicateGuestIds[] = $guest->id;
            }

            if ($guest->phone) {
                $this->recordPhone($guestIdsByName[$nameKey], $guest->phone);
            }
        }

        foreach (DB::table('orders')->select(['id', 'guest_name', 'phone'])->orderBy('id')->get() as $order) {
            $guestName = trim((string) $order->guest_name);

            if ($guestName === '') {
                continue;
            }

            $nameKey = mb_strtolower($guestName);

            if (! isset($guestIdsByName[$nameKey])) {
                $guestIdsByName[$nameKey] = DB::table('guests')->insertGetId([
                    'name' => $guestName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $guestId = $guestIdsByName[$nameKey];

            DB::table('orders')->where('id', $order->id)->update([
                'guest_id' => $guestId,
            ]);

            if ($order->phone) {
                $this->recordPhone($guestId, $order->phone);
            }
        }

        if ($duplicateGuestIds !== []) {
            DB::table('guests')->whereIn('id', $duplicateGuestIds)->delete();
        }

        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique('guests_name_phone_unique');
            $table->dropColumn('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('name');
        });

        foreach (DB::table('guests')->orderBy('id')->get() as $guest) {
            $phone = DB::table('guest_phones')
                ->where('guest_id', $guest->id)
                ->orderBy('id')
                ->value('phone');

            if ($phone !== null) {
                DB::table('guests')->where('id', $guest->id)->update(['phone' => $phone]);
            }
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['guest_id']);
            $table->dropColumn('guest_id');
        });

        Schema::dropIfExists('guest_phones');

        Schema::table('guests', function (Blueprint $table) {
            $table->unique(['name', 'phone']);
        });
    }

    private function recordPhone(int $guestId, string $phone): void
    {
        $phone = trim($phone);

        if ($phone === '') {
            return;
        }

        DB::table('guest_phones')->insertOrIgnore([
            'guest_id' => $guestId,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
