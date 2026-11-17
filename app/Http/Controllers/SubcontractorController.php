<?php

namespace App\Http\Controllers;

use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubcontractorController extends Controller
{
    public function index(): View
    {
        abort_unless(request()->user()->isFactoryWorkflow(), 403);

        $subcontractors = Subcontractor::query()
            ->addSelect($this->metricSubqueries())
            ->orderBy('name')
            ->paginate(20);

        return view('subcontractors.index', compact('subcontractors'));
    }

    public function create(): View
    {
        abort_unless(request()->user()->isFactoryWorkflow(), 403);

        return view('subcontractors.form', [
            'subcontractor' => new Subcontractor(['is_active' => true]),
            'isEditing' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isFactoryWorkflow(), 403);

        $subcontractor = Subcontractor::create($this->validatedData($request));

        return redirect()
            ->route('subcontractors.show', $subcontractor)
            ->with('success', 'Data subkontraktor berhasil ditambahkan.');
    }

    public function show(Subcontractor $subcontractor): View
    {
        abort_unless(request()->user()->isFactoryWorkflow(), 403);

        $subcontractor = Subcontractor::query()
            ->addSelect($this->metricSubqueries())
            ->findOrFail($subcontractor->id);

        $assignments = $subcontractor->assignments()
            ->whereNull('cancelled_at')
            ->with([
                'orderItem.order:id,order_id,deadline,status',
                'orderItem.category:id,name',
            ])
            ->latest('id')
            ->paginate(20);

        $categoryBreakdown = SubcontractorAssignment::query()
            ->join('order_items', 'order_items.id', '=', 'subcontractor_assignments.order_item_id')
            ->leftJoin('categories', 'categories.id', '=', 'order_items.category_id')
            ->where('subcontractor_assignments.subcontractor_id', $subcontractor->id)
            ->whereNull('subcontractor_assignments.cancelled_at')
            ->selectRaw(
                "COALESCE(categories.name, 'Tanpa kategori') AS category_name, SUM(subcontractor_assignments.assigned_quantity) AS assigned_quantity, SUM(CASE WHEN subcontractor_assignments.status = 'Distribusi' THEN subcontractor_assignments.assigned_quantity ELSE 0 END) AS completed_quantity, SUM(subcontractor_assignments.assigned_quantity * order_items.price) AS assigned_value, SUM(CASE WHEN subcontractor_assignments.status = 'Distribusi' THEN subcontractor_assignments.assigned_quantity * order_items.price ELSE 0 END) AS completed_value",
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('category_name')
            ->get();

        return view('subcontractors.show', compact('subcontractor', 'categoryBreakdown', 'assignments'));
    }

    public function edit(Subcontractor $subcontractor): View
    {
        abort_unless(request()->user()->isFactoryWorkflow(), 403);

        return view('subcontractors.form', [
            'subcontractor' => $subcontractor,
            'isEditing' => true,
        ]);
    }

    public function update(Request $request, Subcontractor $subcontractor): RedirectResponse
    {
        abort_unless($request->user()->isFactoryWorkflow(), 403);

        $subcontractor->update($this->validatedData($request));

        return redirect()
            ->route('subcontractors.show', $subcontractor)
            ->with('success', 'Data subkontraktor berhasil diperbarui.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string, Builder>
     */
    private function metricSubqueries(): array
    {
        $assignedItems = fn (): Builder => SubcontractorAssignment::query()
            ->join('order_items', 'order_items.id', '=', 'subcontractor_assignments.order_item_id')
            ->whereColumn('subcontractor_assignments.subcontractor_id', 'subcontractors.id')
            ->whereNull('subcontractor_assignments.cancelled_at');

        return [
            'assigned_orders_count' => $assignedItems()
                ->selectRaw('COUNT(DISTINCT order_items.order_id)'),
            'completed_orders_count' => $assignedItems()
                ->selectRaw('COUNT(DISTINCT order_items.order_id)')
                ->whereNotExists(function (QueryBuilder $query): void {
                    $query->selectRaw('1')
                        ->from('subcontractor_assignments AS incomplete_assignments')
                        ->join('order_items AS incomplete_items', 'incomplete_items.id', '=', 'incomplete_assignments.order_item_id')
                        ->whereColumn('incomplete_items.order_id', 'order_items.order_id')
                        ->whereColumn('incomplete_assignments.subcontractor_id', 'subcontractor_assignments.subcontractor_id')
                        ->whereNull('incomplete_assignments.cancelled_at')
                        ->where('incomplete_assignments.status', '!=', 'Distribusi');
                }),
            'assigned_quantity' => $assignedItems()
                ->selectRaw('COALESCE(SUM(subcontractor_assignments.assigned_quantity), 0)'),
            'completed_quantity' => $assignedItems()
                ->selectRaw("COALESCE(SUM(CASE WHEN subcontractor_assignments.status = 'Distribusi' THEN subcontractor_assignments.assigned_quantity ELSE 0 END), 0)"),
            'assigned_value' => $assignedItems()
                ->selectRaw('COALESCE(SUM(subcontractor_assignments.assigned_quantity * order_items.price), 0)'),
            'completed_value' => $assignedItems()
                ->selectRaw("COALESCE(SUM(CASE WHEN subcontractor_assignments.status = 'Distribusi' THEN subcontractor_assignments.assigned_quantity * order_items.price ELSE 0 END), 0)"),
        ];
    }
}
