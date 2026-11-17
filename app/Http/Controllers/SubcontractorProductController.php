<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubcontractorProductController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isFactoryWorkflow(), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:Produksi,Proses Kirim,Distribusi'],
            'view' => ['nullable', Rule::in(['all', 'subcontractor', 'order', 'product'])],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? '';
        $view = $validated['view'] ?? 'all';
        $applyAssignmentFilters = function (Builder $query) use ($status, $search): void {
            $query->whereNull('cancelled_at')
                ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                    $query->whereHas('subcontractor', fn (Builder $subcontractorQuery) => $subcontractorQuery
                        ->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orderItem', fn (Builder $itemQuery) => $itemQuery
                            ->where('product', 'like', "%{$search}%")
                            ->orWhereHas('order', fn (Builder $orderQuery) => $orderQuery
                                ->where('order_id', 'like', "%{$search}%")));
                }));
        };

        $assignments = null;
        $subcontractors = null;
        $orders = null;
        $products = null;

        if ($view === 'subcontractor') {
            $subcontractors = Subcontractor::query()
                ->whereHas('assignments', fn (Builder $query) => $applyAssignmentFilters($query))
                ->with(['assignments' => fn (Builder $query) => $applyAssignmentFilters($query)
                    ->with(['orderItem:id,order_id,product,category_id,qty,price', 'orderItem.order:id,order_id'])])
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString();
        } elseif ($view === 'order') {
            $orders = Order::query()
                ->whereHas('items.subcontractorAssignments', fn (Builder $query) => $applyAssignmentFilters($query))
                ->with(['items' => fn (Builder $query) => $query
                    ->whereHas('subcontractorAssignments', fn (Builder $assignmentQuery) => $applyAssignmentFilters($assignmentQuery))
                    ->with(['subcontractorAssignments' => fn (Builder $assignmentQuery) => $applyAssignmentFilters($assignmentQuery)
                        ->with('subcontractor:id,name')])])
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        } elseif ($view === 'product') {
            $products = OrderItem::query()
                ->whereHas('subcontractorAssignments', fn (Builder $query) => $applyAssignmentFilters($query))
                ->with([
                    'order:id,order_id,deadline,status',
                    'category:id,name',
                    'subcontractorAssignments' => fn (Builder $query) => $applyAssignmentFilters($query)
                        ->with('subcontractor:id,name'),
                ])
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        } else {
            $assignmentQuery = SubcontractorAssignment::query();
            $applyAssignmentFilters($assignmentQuery);
            $assignments = $assignmentQuery
                ->with([
                    'subcontractor:id,name',
                    'orderItem:id,order_id,product,category_id,qty',
                    'orderItem.order:id,order_id,deadline,status',
                    'orderItem.category:id,name',
                ])
                ->orderByRaw('CASE WHEN target_deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('target_deadline')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        }

        return view('subcontractor-products.index', compact(
            'assignments',
            'subcontractors',
            'orders',
            'products',
            'search',
            'status',
            'view',
        ));
    }
}
