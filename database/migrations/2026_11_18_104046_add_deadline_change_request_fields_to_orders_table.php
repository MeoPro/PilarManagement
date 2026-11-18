<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
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
            $table->date('pending_deadline')->nullable()->after('deadline');
            $table->text('pending_deadline_reason')->nullable()->after('pending_deadline');
            $table->foreignId('pending_deadline_requested_by')
                ->nullable()
                ->after('pending_deadline_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('pending_deadline_requested_at')->nullable()->after('pending_deadline_requested_by');
            $table->date('factory_deadline')->nullable()->after('pending_deadline_requested_at');
            $table->timestamp('factory_started_at')->nullable()->after('factory_deadline');
        });

        DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.factory_started_at')
            ->whereNotIn('orders.status', ['Selesai', 'Cancel'])
            ->where(function ($query): void {
                $query->where('order_items.status', 'Produksi')
                    ->orWhere('order_items.factory_status', 'Produksi');
            })
            ->select('orders.id', 'orders.deadline')
            ->selectRaw('MIN(COALESCE(order_items.qty_progress_updated_at, order_items.updated_at)) AS factory_started_at')
            ->groupBy('orders.id', 'orders.deadline')
            ->orderBy('orders.id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $factoryStartedAt = Carbon::parse($order->factory_started_at)->startOfDay();
                    $factoryDeadline = null;

                    if ($order->deadline !== null) {
                        $deliveryDeadline = Carbon::parse($order->deadline)->startOfDay();
                        $twoMonthFactoryWindow = $factoryStartedAt->copy()->addMonthsNoOverflow(2);
                        $factoryDeadline = $deliveryDeadline->greaterThanOrEqualTo($twoMonthFactoryWindow)
                            ? $deliveryDeadline->copy()->subMonthNoOverflow()
                            : $deliveryDeadline->copy()->subWeek();

                        if ($factoryDeadline->lessThan($factoryStartedAt)) {
                            $factoryDeadline = $factoryStartedAt;
                        }
                    }

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'factory_started_at' => $factoryStartedAt,
                            'factory_deadline' => $factoryDeadline?->toDateString(),
                        ]);
                }
            }, 'orders.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['pending_deadline_requested_by']);
            $table->dropColumn([
                'pending_deadline',
                'pending_deadline_reason',
                'pending_deadline_requested_by',
                'pending_deadline_requested_at',
                'factory_deadline',
                'factory_started_at',
            ]);
        });
    }
};
