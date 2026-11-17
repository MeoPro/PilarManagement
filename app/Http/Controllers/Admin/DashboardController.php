<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $factoryDashboard = $user->isFactoryWorkflow() && ! $user->isSalesWorkflow();
        $factoryStats = [];
        $orderStats = null;
        $topDebtorGuests = collect();

        if ($factoryDashboard && $user->canAccessMenu('orders')) {
            $factoryStats['active_orders'] = Order::query()
                ->whereIn('status', ['Proses Produksi', 'Finishing'])
                ->count();
        }

        if ($factoryDashboard && $user->canAccessMenu('order_products')) {
            $factoryStats['products_in_production'] = OrderItem::query()
                ->where('status', 'Produksi')
                ->whereHas('order', fn (Builder $query) => $query->whereNotIn('status', ['Cancel', 'Selesai']))
                ->count();
        }

        if (! $factoryDashboard && $user->canAccessMenu('orders')) {
            $orderStats = Order::query()->selectRaw(
                'COUNT(*) AS total_orders, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_orders, SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) AS active_orders, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_orders',
                ['None', 'None', 'Proses Produksi', 'Finishing', 'Selesai'],
            )->first();

            if ($user->isSalesWorkflow()) {
                $orderStats->total_revenue = Order::query()
                    ->where('status', '!=', 'Cancel')
                    ->sum('total_order');
            }
        }

        if (! $factoryDashboard && $user->canAccessMenu('guests')) {
            $unpaidCompletedOrders = fn (Builder $query) => $query
                ->where('status', 'Selesai')
                ->whereColumn('paid_amount', '<', 'total_order');

            $topDebtorGuests = Guest::query()
                ->whereHas('orders', $unpaidCompletedOrders)
                ->addSelect([
                    'debt_amount' => Order::query()
                        ->selectRaw('COALESCE(SUM(total_order - paid_amount), 0)')
                        ->whereColumn('guest_id', 'guests.id')
                        ->where($unpaidCompletedOrders),
                    'oldest_debt_at' => Order::query()
                        ->select('created_at')
                        ->whereColumn('guest_id', 'guests.id')
                        ->where($unpaidCompletedOrders)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->limit(1),
                ])
                ->withCasts(['oldest_debt_at' => 'datetime'])
                ->orderBy('oldest_debt_at')
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'name']);
        }

        return view('admin.index', compact(
            'factoryDashboard',
            'factoryStats',
            'orderStats',
            'topDebtorGuests',
        ));
    }
}
