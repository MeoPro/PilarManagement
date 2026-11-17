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
        Schema::create('subcontractor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcontractor_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('assigned_quantity');
            $table->unsignedInteger('qty_completed')->default(0);
            $table->string('status')->default('Produksi');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['subcontractor_id', 'cancelled_at']);
            $table->index(['order_item_id', 'cancelled_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('factory_status')->default('None')->after('status');
        });

        DB::table('order_items')->update([
            'factory_status' => DB::raw('status'),
        ]);

        DB::table('order_items')
            ->whereNotNull('subcontractor_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $item): void {
                $assignedQuantity = (int) $item->qty;
                $completedQuantity = $item->status === 'Distribusi'
                    ? $assignedQuantity
                    : min($assignedQuantity, (int) $item->qty_completed);

                DB::table('subcontractor_assignments')->insert([
                    'order_item_id' => $item->id,
                    'subcontractor_id' => $item->subcontractor_id,
                    'created_by_id' => null,
                    'assigned_quantity' => $assignedQuantity,
                    'qty_completed' => $completedQuantity,
                    'status' => in_array($item->status, ['Proses Kirim', 'Distribusi', 'Ready'], true)
                        ? ($item->status === 'Ready' ? 'Distribusi' : $item->status)
                        : 'Produksi',
                    'cancelled_at' => null,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ]);

                DB::table('order_items')
                    ->where('id', $item->id)
                    ->update([
                        'factory_status' => 'None',
                        'qty_completed' => 0,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('factory_status');
        });

        Schema::dropIfExists('subcontractor_assignments');
    }
};
