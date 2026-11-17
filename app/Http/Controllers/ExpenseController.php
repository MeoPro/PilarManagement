<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $today = now($request->user()->timezone())->toDateString();
        $toDate = $filters['to'] ?? $today;
        $query = Expense::query()->with('createdBy:id,name')->latest('occurred_at')->latest('id');
        $unit = $request->user()->activeBusinessUnit();
        $query->where('business_unit', $unit);

        if (filled($filters['from'] ?? null)) {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }
        $query->whereDate('occurred_at', '<=', $toDate);

        return view('expenses.index', [
            'expenses' => $query->paginate(20)->withQueryString(),
            'selectedUnit' => $unit,
            'unitLabels' => $this->unitLabels(),
            'fromDate' => $filters['from'] ?? '',
            'toDate' => $toDate,
        ]);
    }

    public function create(Request $request): View
    {
        return view('expenses.form', [
            'expense' => new Expense(['occurred_at' => now($request->user()->timezone())->toDateString()]),
            'isEditing' => false,
            'unitLabels' => $this->unitLabels(),
            'selectedUnit' => $request->user()->activeBusinessUnit(),
            'timezoneLabel' => $request->user()->timezoneLabel(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeUnitPrice($request);
        $validated = $request->validate($this->validationRules());
        $unit = $request->user()->activeBusinessUnit();

        Expense::create([
            ...$this->expenseData($validated),
            'business_unit' => $unit,
            'created_by_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('expenses.index')
            ->with('success', 'Pengeluaran berhasil dicatat.');
    }

    public function show(Request $request, Expense $expense): View
    {
        $this->authorizeExpenseUnit($request->user(), $expense);

        return view('expenses.show', compact('expense'));
    }

    public function edit(Request $request, Expense $expense): View
    {
        $this->authorizeExpenseUnit($request->user(), $expense);

        return view('expenses.form', [
            'expense' => $expense,
            'isEditing' => true,
            'unitLabels' => $this->unitLabels(),
            'selectedUnit' => $request->user()->activeBusinessUnit(),
        ]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeExpenseUnit($request->user(), $expense);
        $this->normalizeUnitPrice($request);
        $validated = $request->validate($this->validationRules());
        $expense->update($this->expenseData($validated));

        return redirect()
            ->route('expenses.show', $expense)
            ->with('success', 'Pengeluaran berhasil diperbarui.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeExpenseUnit($request->user(), $expense);
        $expense->delete();

        return redirect()
            ->route('expenses.index')
            ->with('success', 'Pengeluaran berhasil dihapus.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function validationRules(): array
    {
        $rules = [
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'occurred_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function expenseData(array $validated): array
    {
        $quantity = (float) $validated['quantity'];
        $unitPrice = (float) $validated['unit_price'];

        return [
            'description' => trim($validated['description']),
            'quantity' => $quantity,
            'unit' => filled($validated['unit'] ?? null) ? trim($validated['unit']) : null,
            'unit_price' => $unitPrice,
            'total' => round($quantity * $unitPrice, 2),
            'occurred_at' => $validated['occurred_at'],
            'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
        ];
    }

    private function authorizeExpenseUnit(User $user, Expense $expense): void
    {
        abort_unless(
            $user->isOwner()
                ? $expense->business_unit === $user->activeBusinessUnit()
                : in_array($expense->business_unit, $user->accessibleUnits(), true),
            404,
        );
    }

    private function userUnit(User $user): string
    {
        $unit = $user->accessibleUnits()[0] ?? null;
        abort_unless(in_array($unit, [User::UNIT_BALI, User::UNIT_FACTORY], true), 403);

        return $unit;
    }

    /**
     * @return array<string, string>
     */
    private function unitLabels(): array
    {
        return [
            User::UNIT_BALI => 'Pilar Bali',
            User::UNIT_FACTORY => 'Pilar Factory',
        ];
    }

    private function normalizeUnitPrice(Request $request): void
    {
        $value = $request->input('unit_price');
        if (! is_string($value)) {
            return;
        }

        $value = trim($value);
        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $value) === 1) {
            $request->merge(['unit_price' => str_replace(',', '', $value)]);
        }
    }
}
