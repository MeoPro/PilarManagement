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
            'view' => ['nullable', 'in:combined'],
        ]);
        $isCombinedView = ($filters['view'] ?? null) === 'combined';
        abort_unless(! $isCombinedView || $user->isOwner(), 403);
        $businessUnits = $isCombinedView
            ? [User::UNIT_BALI, User::UNIT_FACTORY]
            : [$businessUnit];
        $today = now($user->timezone())->toDateString();
        $fromDate = $filters['from'] ?? now($user->timezone())->startOfMonth()->toDateString();
        $toDate = $filters['to'] ?? $today;

        $expenseQuery = DB::table('expenses')
            ->whereIn('business_unit', $businessUnits)
            ->whereDate('occurred_at', '>=', $fromDate)
            ->whereDate('occurred_at', '<=', $toDate);
        $expenseTotal = (float) (clone $expenseQuery)->sum('total');
        $expenseTotalsByUnit = $isCombinedView
            ? (clone $expenseQuery)
                ->select('business_unit')
                ->selectRaw('SUM(total) as amount')
                ->groupBy('business_unit')
                ->pluck('amount', 'business_unit')
            : collect();
        $transactionQueries = [
            (clone $expenseQuery)->select([
                'id',
                'occurred_at as transaction_date',
                DB::raw("'Keluar' as direction"),
                DB::raw("CASE WHEN category = 'Material' THEN 'Pengeluaran Material' ELSE 'Pengeluaran Additional' END as category"),
                'description as reference',
                DB::raw("'Operasional' as counterparty"),
                'total as amount',
                'note',
                DB::raw('0 as order_pk'),
                DB::raw('0 as material_pk'),
                'id as expense_pk',
            ]),
        ];
        $materialPurchaseQuery = DB::table('material_transactions')
            ->join('materials', 'materials.id', '=', 'material_transactions.material_id')
            ->whereIn('materials.business_unit', $businessUnits)
            ->where('material_transactions.type', 'in')
            ->whereDate('material_transactions.occurred_at', '>=', $fromDate)
            ->whereDate('material_transactions.occurred_at', '<=', $toDate);
        $materialPurchaseTotal = (float) (clone $materialPurchaseQuery)
            ->sum(DB::raw('material_transactions.quantity * material_transactions.unit_price'));
        $materialPurchaseTotalsByUnit = $isCombinedView
            ? (clone $materialPurchaseQuery)
                ->selectRaw('materials.business_unit as business_unit')
                ->selectRaw('SUM(material_transactions.quantity * material_transactions.unit_price) as amount')
                ->groupBy('business_unit')
                ->pluck('amount', 'business_unit')
            : collect();
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
            'materials.id as material_pk',
            DB::raw('0 as expense_pk'),
        ]);

        $payrollQuery = DB::table('payroll_payments')
            ->join('employees', 'employees.id', '=', 'payroll_payments.employee_id')
            ->whereIn('employees.business_unit', $businessUnits)
            ->whereDate('payroll_payments.paid_at', '>=', $fromDate)
            ->whereDate('payroll_payments.paid_at', '<=', $toDate);
        $salaryTotal = (float) (clone $payrollQuery)->sum(DB::raw(
            'CASE WHEN payroll_payments.salary_paid > payroll_payments.cashbond_paid '
                .'THEN payroll_payments.salary_paid - payroll_payments.cashbond_paid ELSE 0 END',
        ));
        $salaryTotalsByUnit = $isCombinedView
            ? (clone $payrollQuery)
                ->selectRaw('employees.business_unit as business_unit')
                ->selectRaw(
                    'SUM(CASE WHEN payroll_payments.salary_paid > payroll_payments.cashbond_paid '
                        .'THEN payroll_payments.salary_paid - payroll_payments.cashbond_paid ELSE 0 END) as amount',
                )
                ->groupBy('business_unit')
                ->pluck('amount', 'business_unit')
            : collect();
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
                DB::raw('0 as material_pk'),
                DB::raw('0 as expense_pk'),
            ]);

        $cashbondRepaymentQuery = (clone $payrollQuery)
            ->where('payroll_payments.salary_paid', 0)
            ->where('payroll_payments.cashbond_paid', '>', 0);
        $cashbondRepaymentTotal = (float) (clone $cashbondRepaymentQuery)
            ->sum('payroll_payments.cashbond_paid');
        $cashbondRepaymentTotalsByUnit = $isCombinedView
            ? (clone $cashbondRepaymentQuery)
                ->selectRaw('employees.business_unit as business_unit')
                ->selectRaw('SUM(payroll_payments.cashbond_paid) as amount')
                ->groupBy('business_unit')
                ->pluck('amount', 'business_unit')
            : collect();
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
            DB::raw('0 as material_pk'),
            DB::raw('0 as expense_pk'),
        ]);

        $cashbondAdvanceQuery = DB::table('cashbond_advances')
            ->join('employees', 'employees.id', '=', 'cashbond_advances.employee_id')
            ->whereIn('employees.business_unit', $businessUnits)
            ->whereBetween('cashbond_advances.advance_date', [$fromDate, $toDate]);
        $cashbondAdvanceTotal = (float) (clone $cashbondAdvanceQuery)->sum('cashbond_advances.amount');
        $cashbondAdvanceTotalsByUnit = $isCombinedView
            ? (clone $cashbondAdvanceQuery)
                ->selectRaw('employees.business_unit as business_unit')
                ->selectRaw('SUM(cashbond_advances.amount) as amount')
                ->groupBy('business_unit')
                ->pluck('amount', 'business_unit')
            : collect();
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
            DB::raw('0 as material_pk'),
            DB::raw('0 as expense_pk'),
        ]);

        $orderRevenue = 0.0;
        $factoryOrderValueTotal = 0.0;
        $factorySaleTotal = 0.0;
        $monthlyOrderTotal = 0.0;
        $openingPaidAmount = 0.0;
        if ($businessUnit === User::UNIT_FACTORY || $isCombinedView) {
            $factoryOrderValueQuery = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.is_stock_order', false)
                ->where('orders.status', '!=', 'Cancel')
                ->whereNotNull('order_items.factory_sale_price')
                ->whereDate(
                    DB::raw('COALESCE(order_items.factory_price_set_at, order_items.created_at)'),
                    '>=',
                    $fromDate,
                )
                ->whereDate(
                    DB::raw('COALESCE(order_items.factory_price_set_at, order_items.created_at)'),
                    '<=',
                    $toDate,
                );
            $factoryOrderValueSql = "CASE WHEN order_items.source = 'Stock' "
                .'THEN order_items.factory_sale_price * order_items.stock_factory_quantity '
                .'ELSE order_items.factory_sale_price * order_items.qty END';
            $factoryOrderValueTotal = (float) (clone $factoryOrderValueQuery)
                ->sum(DB::raw($factoryOrderValueSql));
            $transactionQueries[] = (clone $factoryOrderValueQuery)
                ->whereRaw("({$factoryOrderValueSql}) > 0")
                ->select([
                    'order_items.id as id',
                    DB::raw('COALESCE(order_items.factory_price_set_at, order_items.created_at) as transaction_date'),
                    DB::raw("'Order' as direction"),
                    DB::raw("'Nilai order PF ke PB' as category"),
                    'orders.order_id as reference',
                    DB::raw("'Pilar Bali' as counterparty"),
                    DB::raw("{$factoryOrderValueSql} as amount"),
                    DB::raw('NULL as note'),
                    'orders.id as order_pk',
                    DB::raw('0 as material_pk'),
                    DB::raw('0 as expense_pk'),
                ]);

            $acceptedQuantitySql = 'CASE '
                .'WHEN COALESCE(order_items.quality_good_quantity, 0) > 0 THEN '
                .'CASE WHEN order_items.quality_good_quantity > order_items.qty '
                .'THEN order_items.qty ELSE order_items.quality_good_quantity END '
                ."WHEN order_items.status = 'Ready' THEN order_items.qty ELSE 0 END";
            $factorySaleQuantitySql = "CASE WHEN order_items.source = 'Stock' THEN "
                ."CASE WHEN ({$acceptedQuantitySql}) > order_items.stock_factory_quantity "
                ."THEN order_items.stock_factory_quantity ELSE ({$acceptedQuantitySql}) END "
                ."ELSE ({$acceptedQuantitySql}) END";
            $factorySaleQuery = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.is_stock_order', false)
                ->where('orders.status', '!=', 'Cancel')
                ->whereNotNull('order_items.factory_sale_price')
                ->whereNotNull('order_items.factory_handover_at')
                ->whereRaw("({$factorySaleQuantitySql}) > 0")
                ->whereDate(
                    DB::raw('COALESCE(order_items.pb_accepted_at, order_items.factory_handover_at)'),
                    '>=',
                    $fromDate,
                )
                ->whereDate(
                    DB::raw('COALESCE(order_items.pb_accepted_at, order_items.factory_handover_at)'),
                    '<=',
                    $toDate,
                );
            $factorySaleAmountSql = "order_items.factory_sale_price * ({$factorySaleQuantitySql})";
            $factorySaleTotal = (float) (clone $factorySaleQuery)
                ->sum(DB::raw($factorySaleAmountSql));
            $transactionQueries[] = (clone $factorySaleQuery)->select([
                'order_items.id as id',
                DB::raw('COALESCE(order_items.pb_accepted_at, order_items.factory_handover_at) as transaction_date'),
                DB::raw($isCombinedView ? "'Internal' as direction" : "'Omzet' as direction"),
                DB::raw($isCombinedView ? "'Penjualan internal PF ke PB' as category" : "'Penjualan PF ke PB' as category"),
                'orders.order_id as reference',
                DB::raw("'Pilar Bali' as counterparty"),
                DB::raw("{$factorySaleAmountSql} as amount"),
                DB::raw('NULL as note'),
                'orders.id as order_pk',
                DB::raw('0 as material_pk'),
                DB::raw('0 as expense_pk'),
            ]);
        }

        if ($businessUnit === User::UNIT_BALI || $isCombinedView) {
            $monthStart = now($user->timezone())->startOfMonth();
            $nextMonthStart = $monthStart->copy()->addMonth();
            $monthlyOrderTotal = (float) Order::query()
                ->where('is_stock_order', false)
                ->where('status', '!=', 'Cancel')
                ->where('created_at', '>=', $monthStart->toDateTimeString())
                ->where('created_at', '<', $nextMonthStart->toDateTimeString())
                ->sum('total_order');

            $orderPaymentQuery = DB::table('order_payments')
                ->join('orders', 'orders.id', '=', 'order_payments.order_id')
                ->where('orders.is_stock_order', false)
                ->whereDate('order_payments.paid_at', '>=', $fromDate)
                ->whereDate('order_payments.paid_at', '<=', $toDate);
            $orderRevenue = (float) (clone $orderPaymentQuery)->sum('order_payments.amount');
            $openingPaidAmount = (float) Order::query()
                ->where('is_stock_order', false)
                ->sum('opening_paid_amount');
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
                DB::raw('0 as material_pk'),
                DB::raw('0 as expense_pk'),
            ]);
        }

        $totalIncoming = $orderRevenue + $cashbondRepaymentTotal;
        $totalOutgoing = $expenseTotal + $materialPurchaseTotal + $salaryTotal + $cashbondAdvanceTotal;
        $unitSummaries = [];
        if ($isCombinedView) {
            $baliOutgoing = (float) $expenseTotalsByUnit->get(User::UNIT_BALI, 0)
                + (float) $materialPurchaseTotalsByUnit->get(User::UNIT_BALI, 0)
                + (float) $salaryTotalsByUnit->get(User::UNIT_BALI, 0)
                + (float) $cashbondAdvanceTotalsByUnit->get(User::UNIT_BALI, 0);
            $factoryOutgoing = (float) $expenseTotalsByUnit->get(User::UNIT_FACTORY, 0)
                + (float) $materialPurchaseTotalsByUnit->get(User::UNIT_FACTORY, 0)
                + (float) $salaryTotalsByUnit->get(User::UNIT_FACTORY, 0)
                + (float) $cashbondAdvanceTotalsByUnit->get(User::UNIT_FACTORY, 0);
            $baliIncoming = $orderRevenue + (float) $cashbondRepaymentTotalsByUnit->get(User::UNIT_BALI, 0);
            $factoryIncoming = $factorySaleTotal + (float) $cashbondRepaymentTotalsByUnit->get(User::UNIT_FACTORY, 0);
            $unitSummaries = [
                User::UNIT_BALI => [
                    'label' => 'Pilar Bali',
                    'incoming' => $baliIncoming,
                    'outgoing' => $baliOutgoing,
                    'net' => $baliIncoming - $baliOutgoing,
                ],
                User::UNIT_FACTORY => [
                    'label' => 'Pilar Factory',
                    'incoming' => $factoryIncoming,
                    'outgoing' => $factoryOutgoing,
                    'net' => $factoryIncoming - $factoryOutgoing,
                ],
            ];
        }
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
            'businessUnitLabel' => $isCombinedView
                ? 'Gabungan PB + PF'
                : ($businessUnit === User::UNIT_FACTORY ? 'Pilar Factory' : 'Pilar Bali'),
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'orderRevenue' => $orderRevenue,
            'factoryOrderValueTotal' => $factoryOrderValueTotal,
            'factorySaleTotal' => $factorySaleTotal,
            'factoryNetRevenue' => $isCombinedView
                ? ($unitSummaries[User::UNIT_FACTORY]['net'] ?? 0)
                : $factorySaleTotal - $totalOutgoing,
            'isFactoryUnit' => ! $isCombinedView && $businessUnit === User::UNIT_FACTORY,
            'isCombinedView' => $isCombinedView,
            'unitSummaries' => $unitSummaries,
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
