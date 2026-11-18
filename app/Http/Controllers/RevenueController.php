<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RevenueController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $businessUnit = $user->activeBusinessUnit();
        abort_unless(in_array($businessUnit, [User::UNIT_BALI, User::UNIT_FACTORY], true), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = now($user->timezone())->toDateString();
        $fromDate = $filters['from'] ?? now($user->timezone())->startOfMonth()->toDateString();
        $toDate = $filters['to'] ?? $today;

        $expenseQuery = DB::table('expenses')
            ->where('business_unit', $businessUnit)
            ->whereBetween('occurred_at', [$fromDate, $toDate]);
        $expenseTotal = (float) (clone $expenseQuery)->sum('total');
        $transactionQueries = [
            (clone $expenseQuery)->select([
                'id',
                'occurred_at as transaction_date',
                DB::raw("'Keluar' as direction"),
                DB::raw("'Pengeluaran' as category"),
                'description as reference',
                DB::raw("'Operasional' as counterparty"),
                'total as amount',
                'note',
                DB::raw('0 as order_pk'),
            ]),
        ];
        $materialPurchaseQuery = DB::table('material_transactions')
            ->join('materials', 'materials.id', '=', 'material_transactions.material_id')
            ->where('materials.business_unit', $businessUnit)
            ->where('material_transactions.type', 'in')
            ->whereDate('material_transactions.occurred_at', '>=', $fromDate)
            ->whereDate('material_transactions.occurred_at', '<=', $toDate);
        $materialPurchaseTotal = (float) (clone $materialPurchaseQuery)
            ->sum(DB::raw('material_transactions.quantity * material_transactions.unit_price'));
        $transactionQueries[] = (clone $materialPurchaseQuery)->select([
            'material_transactions.id as id',
            'material_transactions.occurred_at as transaction_date',
            DB::raw("'Keluar' as direction"),
            DB::raw("'Pembelian material' as category"),
            'materials.name as reference',
            DB::raw("'Material' as counterparty"),
            DB::raw('material_transactions.quantity * material_transactions.unit_price as amount'),
            'material_transactions.note as note',
            DB::raw('0 as order_pk'),
        ]);

        $payrollQuery = DB::table('payroll_payments')
            ->join('employees', 'employees.id', '=', 'payroll_payments.employee_id')
            ->where('employees.business_unit', $businessUnit)
            ->whereDate('payroll_payments.paid_at', '>=', $fromDate)
            ->whereDate('payroll_payments.paid_at', '<=', $toDate);
        $salaryTotal = (float) (clone $payrollQuery)->sum(DB::raw(
            'CASE WHEN payroll_payments.salary_paid > payroll_payments.cashbond_paid '
                .'THEN payroll_payments.salary_paid - payroll_payments.cashbond_paid ELSE 0 END',
        ));
        $transactionQueries[] = (clone $payrollQuery)
            ->whereRaw('payroll_payments.salary_paid > payroll_payments.cashbond_paid')
            ->select([
                'payroll_payments.id as id',
                'payroll_payments.paid_at as transaction_date',
                DB::raw("'Keluar' as direction"),
                DB::raw("'Gaji' as category"),
                'payroll_payments.period as reference',
                'employees.full_name as counterparty',
                DB::raw('payroll_payments.salary_paid - payroll_payments.cashbond_paid as amount'),
                DB::raw('NULL as note'),
                DB::raw('0 as order_pk'),
            ]);

        $cashbondRepaymentQuery = (clone $payrollQuery)
            ->where('payroll_payments.salary_paid', 0)
            ->where('payroll_payments.cashbond_paid', '>', 0);
        $cashbondRepaymentTotal = (float) (clone $cashbondRepaymentQuery)
            ->sum('payroll_payments.cashbond_paid');
        $transactionQueries[] = (clone $cashbondRepaymentQuery)->select([
            'payroll_payments.id as id',
            'payroll_payments.paid_at as transaction_date',
            DB::raw("'Masuk' as direction"),
            DB::raw("'Pengembalian cashbond' as category"),
            DB::raw("'Pelunasan cashbond' as reference"),
            'employees.full_name as counterparty',
            'payroll_payments.cashbond_paid as amount',
            DB::raw('NULL as note'),
            DB::raw('0 as order_pk'),
        ]);

        $cashbondAdvanceQuery = DB::table('cashbond_advances')
            ->join('employees', 'employees.id', '=', 'cashbond_advances.employee_id')
            ->where('employees.business_unit', $businessUnit)
            ->whereBetween('cashbond_advances.advance_date', [$fromDate, $toDate]);
        $cashbondAdvanceTotal = (float) (clone $cashbondAdvanceQuery)->sum('cashbond_advances.amount');
        $transactionQueries[] = (clone $cashbondAdvanceQuery)->select([
            'cashbond_advances.id as id',
            'cashbond_advances.advance_date as transaction_date',
            DB::raw("'Keluar' as direction"),
            DB::raw("'Uang muka cashbond' as category"),
            DB::raw("'Cashbond karyawan' as reference"),
            'employees.full_name as counterparty',
            'cashbond_advances.amount as amount',
            DB::raw('NULL as note'),
            DB::raw('0 as order_pk'),
        ]);

        $orderRevenue = 0.0;
        $factorySaleTotal = 0.0;
        $monthlyOrderTotal = 0.0;
        $openingPaidAmount = 0.0;
        if ($businessUnit === User::UNIT_FACTORY) {
            $factorySaleQuery = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.source', '!=', 'Stock')
                ->whereNotNull('order_items.factory_sale_price')
                ->whereNotNull('order_items.factory_handover_at')
                ->whereDate('order_items.factory_handover_at', '>=', $fromDate)
                ->whereDate('order_items.factory_handover_at', '<=', $toDate);
            $factorySaleTotal = (float) (clone $factorySaleQuery)
                ->sum(DB::raw('order_items.factory_sale_price * order_items.qty'));
            $transactionQueries[] = (clone $factorySaleQuery)->select([
                'order_items.id as id',
                'order_items.factory_handover_at as transaction_date',
                DB::raw("'Omzet' as direction"),
                DB::raw("'Penjualan PF ke PB' as category"),
                'orders.order_id as reference',
                'orders.guest_name as counterparty',
                DB::raw('order_items.factory_sale_price * order_items.qty as amount'),
                DB::raw('NULL as note'),
                'orders.id as order_pk',
            ]);
        }

        if ($businessUnit === User::UNIT_BALI) {
            $monthStart = now($user->timezone())->startOfMonth();
            $nextMonthStart = $monthStart->copy()->addMonth();
            $monthlyOrderTotal = (float) Order::query()
                ->where('status', '!=', 'Cancel')
                ->where('created_at', '>=', $monthStart->toDateTimeString())
                ->where('created_at', '<', $nextMonthStart->toDateTimeString())
                ->sum('total_order');

            $orderPaymentQuery = DB::table('order_payments')
                ->join('orders', 'orders.id', '=', 'order_payments.order_id')
                ->whereDate('order_payments.paid_at', '>=', $fromDate)
                ->whereDate('order_payments.paid_at', '<=', $toDate);
            $orderRevenue = (float) (clone $orderPaymentQuery)->sum('order_payments.amount');
            $openingPaidAmount = (float) Order::query()->sum('opening_paid_amount');
            $transactionQueries[] = (clone $orderPaymentQuery)->select([
                'order_payments.id as id',
                'order_payments.paid_at as transaction_date',
                DB::raw("'Masuk' as direction"),
                DB::raw("'Pembayaran order' as category"),
                'orders.order_id as reference',
                'orders.guest_name as counterparty',
                'order_payments.amount as amount',
                'order_payments.note as note',
                'orders.id as order_pk',
            ]);
        }

        $totalIncoming = $orderRevenue + $cashbondRepaymentTotal;
        $totalOutgoing = $expenseTotal + $materialPurchaseTotal + $salaryTotal + $cashbondAdvanceTotal;
        $transactionsQuery = array_shift($transactionQueries);
        foreach ($transactionQueries as $transactionQuery) {
            $transactionsQuery->unionAll($transactionQuery);
        }
        $transactions = DB::query()
            ->fromSub($transactionsQuery, 'finance_transactions')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->orderBy('category')
            ->paginate(30)
            ->withQueryString();

        return view('revenue.index', [
            'transactions' => $transactions,
            'businessUnitLabel' => $businessUnit === User::UNIT_FACTORY ? 'Pilar Factory' : 'Pilar Bali',
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'orderRevenue' => $orderRevenue,
            'factorySaleTotal' => $factorySaleTotal,
            'factoryNetRevenue' => $factorySaleTotal - $totalOutgoing,
            'isFactoryUnit' => $businessUnit === User::UNIT_FACTORY,
            'monthlyOrderTotal' => $monthlyOrderTotal,
            'cashbondRepaymentTotal' => $cashbondRepaymentTotal,
            'totalIncoming' => $totalIncoming,
            'expenseTotal' => $expenseTotal,
            'materialPurchaseTotal' => $materialPurchaseTotal,
            'salaryTotal' => $salaryTotal,
            'cashbondAdvanceTotal' => $cashbondAdvanceTotal,
            'totalOutgoing' => $totalOutgoing,
            'netCashFlow' => $totalIncoming - $totalOutgoing,
            'openingPaidAmount' => $openingPaidAmount,
        ]);
    }
}
