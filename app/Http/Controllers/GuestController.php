<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use Illuminate\Http\Request;

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
                $query->select(['id', 'guest_id', 'order_id', 'phone']);
            }])
            ->withCount('orders')
            ->withSum('orders', 'total_order')
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

        return view('guests.index', compact('guests', 'search', 'sort', 'sortOptions'));
    }
}
