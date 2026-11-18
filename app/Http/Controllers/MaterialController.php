<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MaterialController extends Controller
{
    public function index(Request $request): View
    {
        $query = Material::query()
            ->withSum(['transactions as stock_in_quantity' => fn (Builder $query) => $query->where('type', 'in')], 'quantity')
            ->withSum(['transactions as stock_out_quantity' => fn (Builder $query) => $query->where('type', 'out')], 'quantity')
            ->orderBy('name');

        if ($request->user()->isOwner()) {
            $unit = $request->query('business_unit');
            if (in_array($unit, [User::UNIT_BALI, User::UNIT_FACTORY], true)) {
                $query->where('business_unit', $unit);
            }
        } else {
            $query->where('business_unit', $this->userUnit($request->user()));
        }

        $materials = $query->paginate(20)->withQueryString();

        return view('materials.index', [
            'materials' => $materials,
            'selectedUnit' => $request->query('business_unit'),
            'unitLabels' => $this->unitLabels(),
        ]);
    }

    public function create(): View
    {
        return view('materials.form', [
            'material' => new Material,
            'isEditing' => false,
            'unitLabels' => $this->unitLabels(),
            'initialStock' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeMoney($request, 'unit_price');
        $validated = $request->validate([
            'business_unit' => [
                $request->user()->isOwner() ? 'required' : 'nullable',
                Rule::in([User::UNIT_BALI, User::UNIT_FACTORY]),
            ],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'initial_stock' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ]);

        $unit = $request->user()->isOwner()
            ? $validated['business_unit']
            : $this->userUnit($request->user());

        $material = DB::transaction(function () use ($validated, $unit, $request): Material {
            $material = Material::create([
                'business_unit' => $unit,
                'name' => trim($validated['name']),
                'unit' => filled($validated['unit'] ?? null) ? trim($validated['unit']) : null,
                'unit_price' => $validated['unit_price'],
            ]);

            $initialStock = (float) ($validated['initial_stock'] ?? 0);
            if ($initialStock > 0) {
                $material->transactions()->create([
                    'created_by_id' => $request->user()->id,
                    'type' => 'in',
                    'quantity' => $initialStock,
                    'unit_price' => $validated['unit_price'],
                    'note' => 'Stok awal',
                    'occurred_at' => now(),
                ]);
            }

            return $material;
        });

        return redirect()
            ->route('materials.show', $material)
            ->with('success', 'Material berhasil ditambahkan.');
    }

    public function show(Request $request, Material $material): View
    {
        $this->authorizeMaterialUnit($request->user(), $material);
        $material = Material::query()
            ->withSum(['transactions as stock_in_quantity' => fn (Builder $query) => $query->where('type', 'in')], 'quantity')
            ->withSum(['transactions as stock_out_quantity' => fn (Builder $query) => $query->where('type', 'out')], 'quantity')
            ->findOrFail($material->id);
        $transactions = $material->transactions()
            ->with('createdBy:id,name')
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(20);

        $timezone = $request->user()->timezone();
        $timezoneLabel = $request->user()->timezoneLabel();

        return view('materials.show', compact('material', 'transactions', 'timezone', 'timezoneLabel'));
    }

    public function edit(Request $request, Material $material): View
    {
        $this->authorizeMaterialUnit($request->user(), $material);

        return view('materials.form', [
            'material' => $material,
            'isEditing' => true,
            'unitLabels' => $this->unitLabels(),
            'initialStock' => null,
        ]);
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $this->authorizeMaterialUnit($request->user(), $material);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
        ]);

        $material->update([
            'name' => trim($validated['name']),
            'unit' => filled($validated['unit'] ?? null) ? trim($validated['unit']) : null,
        ]);

        return redirect()
            ->route('materials.show', $material)
            ->with('success', 'Material berhasil diperbarui.');
    }

    public function storeTransaction(Request $request, Material $material): RedirectResponse
    {
        $this->authorizeMaterialUnit($request->user(), $material);
        $timezone = $request->user()->timezone();
        $this->normalizeMoney($request, 'unit_price');
        $validated = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_price' => ['required_if:type,in', 'nullable', 'numeric', 'min:0'],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $material, $validated, $timezone): void {
            $lockedMaterial = Material::query()
                ->whereKey($material->id)
                ->lockForUpdate()
                ->firstOrFail();
            $quantity = (float) $validated['quantity'];
            $unitPrice = $validated['type'] === 'in'
                ? (float) $validated['unit_price']
                : $this->weightedAverageUnitPrice($lockedMaterial);

            if ($validated['type'] === 'out') {
                $availableStock = (float) $lockedMaterial->transactions()
                    ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END), 0) AS stock")
                    ->value('stock');

                if ((int) round($quantity * 1000) > (int) round($availableStock * 1000)) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Stok keluar melebihi saldo material yang tersedia.',
                    ]);
                }
            } else {
                $lockedMaterial->update(['unit_price' => $unitPrice]);
            }

            $lockedMaterial->transactions()->create([
                'created_by_id' => $request->user()->id,
                'type' => $validated['type'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
                'occurred_at' => isset($validated['occurred_at'])
                    ? Carbon::parse($validated['occurred_at'], $timezone)->setTimezone(config('app.timezone'))
                    : now(),
            ]);
        });

        return redirect()
            ->route('materials.show', $material)
            ->with('success', 'Transaksi stok berhasil dicatat.');
    }

    private function authorizeMaterialUnit(User $user, Material $material): void
    {
        abort_unless(
            $user->isOwner() || in_array($material->business_unit, $user->accessibleUnits(), true),
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

    private function normalizeMoney(Request $request, string $field): void
    {
        $value = $request->input($field);
        if (! is_string($value)) {
            return;
        }

        $value = trim($value);
        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $value) === 1) {
            $request->merge([$field => str_replace(',', '', $value)]);
        }
    }

    private function weightedAverageUnitPrice(Material $material): float
    {
        $quantityOnHand = 0.0;
        $inventoryValue = 0.0;

        foreach ($material->transactions()->orderBy('created_at')->orderBy('id')->cursor() as $transaction) {
            $quantity = (float) $transaction->quantity;

            if ($transaction->type === 'in') {
                $quantityOnHand += $quantity;
                $inventoryValue += $quantity * (float) $transaction->unit_price;

                continue;
            }

            if ($quantity > $quantityOnHand + 0.001) {
                throw ValidationException::withMessages([
                    'quantity' => 'Riwayat stok material tidak konsisten. Periksa transaksi stok sebelum mencatat pemakaian.',
                ]);
            }

            $averageUnitPrice = $quantityOnHand > 0 ? $inventoryValue / $quantityOnHand : 0;
            $inventoryValue -= $quantity * $averageUnitPrice;
            $quantityOnHand -= $quantity;
        }

        if ($quantityOnHand <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Tidak ada stok dengan harga rata-rata yang dapat dipakai.',
            ]);
        }

        return round($inventoryValue / $quantityOnHand, 2);
    }
}
