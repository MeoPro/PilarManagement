<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GuestController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $guest = Guest::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->first();

        if (! $guest) {
            $guest = Guest::create(['name' => trim($validated['name'])]);
        }

        $guest->load('phones');

        return response()->json([
            'success' => true,
            'guest' => $guest,
        ]);
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $sortOptions = [
            'alphabetical' => 'Nama A-Z',
            'most' => 'Order terbanyak',
            'fewest' => 'Order tersedikit',
        ];
        $sort = $request->query('sort', 'alphabetical');

        if (! array_key_exists($sort, $sortOptions)) {
            $sort = 'alphabetical';
        }

        $guests = Guest::query()
            ->with(['phones', 'orders' => function ($query) {
                $query->select(['id', 'guest_id', 'order_id', 'phone', 'status']);
            }])
            ->addSelect([
                'debt_amount' => Order::query()
                    ->selectRaw('COALESCE(SUM(orders.total_order - orders.paid_amount), 0)')
                    ->whereColumn('orders.guest_id', 'guests.id')
                    ->where('orders.status', 'Selesai')
                    ->whereColumn('orders.paid_amount', '<', 'orders.total_order'),
            ])
            ->addSelect([
                'unpaid_amount' => Order::query()
                    ->selectRaw('COALESCE(SUM(CASE WHEN orders.total_order > orders.paid_amount THEN orders.total_order - orders.paid_amount ELSE 0 END), 0)')
                    ->whereColumn('orders.guest_id', 'guests.id')
                    ->where('orders.status', '!=', 'Cancel'),
            ])
            ->withCount('orders')
            ->withSum(['orders' => fn ($query) => $query->where('status', '!=', 'Cancel')], 'total_order')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhereHas('phones', fn ($phones) => $phones->where('phone', 'like', "%{$search}%"))
                        ->orWhereHas('orders', function ($orders) use ($search) {
                            $orders->where('phone', 'like', "%{$search}%")
                                ->orWhere('order_id', 'like', "%{$search}%");
                        });
                });
            });

        match ($sort) {
            'most' => $guests->orderByDesc('orders_count')->orderByRaw('LOWER(name)'),
            'fewest' => $guests->orderBy('orders_count')->orderByRaw('LOWER(name)'),
            default => $guests->orderByRaw('LOWER(name)'),
        };

        $guests = $guests->paginate(15)->withQueryString();
        $totalGuestDebt = Order::query()
            ->where('status', 'Selesai')
            ->whereColumn('paid_amount', '<', 'total_order')
            ->selectRaw('COALESCE(SUM(total_order - paid_amount), 0) AS total_debt')
            ->value('total_debt');

        return view('guests.index', compact('guests', 'search', 'sort', 'sortOptions', 'totalGuestDebt'));
    }

    public function show(Guest $guest): View
    {
        $guest->load('phones');
        $guest->loadCount('orders');
        $guest->loadSum(['orders' => fn ($query) => $query->where('status', '!=', 'Cancel')], 'total_order');
        $guest->debt_amount = $guest->orders()
            ->where('status', 'Selesai')
            ->whereColumn('paid_amount', '<', 'total_order')
            ->selectRaw('COALESCE(SUM(total_order - paid_amount), 0) AS total_debt')
            ->value('total_debt');
        $orders = $guest->orders()
            ->where('status', '!=', 'Cancel')
            ->with('items.category')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);
        $canDeleteGuest = ! $guest->hasActiveOrders();

        return view('guests.show', compact('guest', 'orders', 'canDeleteGuest'));
    }

    public function edit(Guest $guest): View
    {
        $guest->load('phones');

        return view('guests.edit', compact('guest'));
    }

    public function update(Request $request, Guest $guest): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($guest, $validated): void {
            $guest->update([
                'name' => trim($validated['name']),
                'address' => filled($validated['address'] ?? null) ? trim($validated['address']) : null,
            ]);

            $guest->phones()->delete();
            $phoneEntries = collect($validated['phones'] ?? [])
                ->map(fn (?string $phone): string => trim($phone ?? ''))
                ->filter(fn (string $phone): bool => $phone !== '')
                ->unique()
                ->values();

            foreach ($phoneEntries as $phone) {
                $guest->phones()->create(['phone' => $phone]);
            }

            $guest->orders()->update([
                'guest_name' => trim($validated['name']),
            ]);
        });

        return redirect()
            ->route('guests.show', $guest)
            ->with('success', 'Data tamu berhasil diperbarui.');
    }

    public function destroy(Guest $guest): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($guest): bool {
            $lockedGuest = Guest::query()
                ->lockForUpdate()
                ->findOrFail($guest->id);

            if ($lockedGuest->activeOrders()->lockForUpdate()->exists()) {
                return false;
            }

            $lockedGuest->delete();

            return true;
        });

        if (! $deleted) {
            return back()->with('error', 'Tamu tidak dapat dihapus karena masih memiliki order yang sedang berjalan.');
        }

        return redirect()
            ->route('guests.index')
            ->with('success', 'Data tamu berhasil dihapus.');
    }
}
