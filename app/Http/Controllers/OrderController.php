<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Color;
use App\Models\Guest;
use App\Models\GuestPhone;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $sortOptions = [
            'oldest' => 'Order terlama',
            'most' => 'Produk terbanyak',
            'remaining_more' => 'Waktu tersisa paling banyak',
            'remaining_less' => 'Waktu tersisa paling sedikit',
        ];
        $sort = $request->query('sort', 'remaining_less');

        if (! array_key_exists($sort, $sortOptions)) {
            $sort = 'remaining_less';
        }

        $orders = Order::with('items.category')
            ->withSum('items', 'qty')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('order_id', 'like', "%{$search}%")
                        ->orWhere('guest_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($query) use ($search) {
                            $query->where('product', 'like', "%{$search}%")
                                ->orWhereHas('category', function ($query) use ($search) {
                                    $query->where('name', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', ['Selesai']);

        match ($sort) {
            'oldest' => $orders->oldest('created_at')->oldest('id'),
            'most' => $orders->orderByDesc('items_sum_qty')
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline')
                ->oldest('created_at'),
            'remaining_more' => $orders->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('deadline')
                ->oldest('created_at'),
            default => $orders->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline')
                ->oldest('created_at'),
        };

        $orders = $orders->oldest('id')->paginate(10)->withQueryString();

        return view('orders.index', compact('orders', 'search', 'sort', 'sortOptions'));
    }

    public function create()
    {
        $categories = Category::orderBy('name')->get();
        $colors = Color::orderBy('name')->pluck('name');
        $guests = $this->guestOptions();

        return view('orders.create', compact('categories', 'colors', 'guests'));
    }

    public function checkOrderId(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string|max:255',
        ]);

        $isAvailable = ! Order::where('order_id', $validated['order_id'])->exists();

        return response()->json([
            'available' => $isAvailable,
            'message' => $isAvailable ? null : 'Order ID sudah digunakan.',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);
        $validated['guest_name'] = Guest::findOrFail($validated['guest_id'])->name;

        DB::transaction(function () use ($request, $validated) {

            $total = 0;

            $order = Order::create([
                'order_id' => $validated['order_id'],
                'guest_id' => $validated['guest_id'],
                'guest_name' => $validated['guest_name'],
                'guest_source' => $validated['guest_source'],
                'phone' => $validated['phone'] ?? null,
                'total_order' => 0,
                'deadline' => $validated['deadline'] ?? null,
                'last_edit' => now(),
                'status' => 'None',
                'note' => $validated['note'] ?? null,
            ]);

            $this->rememberGuestPhone($validated['guest_id'], $validated['phone'] ?? null);

            foreach ($request->items as $index => $item) {

                $imagePath = null;

                if ($request->hasFile("items.$index.image")) {
                    $imagePath = $request
                        ->file("items.$index.image")
                        ->store('orders/products', 'public');
                }

                $subtotal = $item['qty'] * $item['price'];

                $total += $subtotal;

                $order->items()->create([
                    'product' => $item['product'],
                    'category_id' => $item['category_id'] ?? null,
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'color' => $item['color'] ?? null,
                    'source' => $item['source'],
                    'status' => $item['status'],
                    'image' => $imagePath,
                ]);
            }

            $order->update([
                'total_order' => $total,
            ]);
        });

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil dibuat.');
    }

    public function show(Order $order)
    {
        $order->load('items.category');

        return view('orders.show', compact('order'));
    }

    public function edit(Order $order)
    {
        $order->load('items.category');

        $categories = Category::orderBy('name')->get();
        $colors = Color::orderBy('name')->pluck('name');
        $existingColors = $order->items->pluck('color')->filter()->unique();
        $colors = $colors->merge($existingColors)->unique()->sort()->values();
        $guests = $this->guestOptions();

        return view('orders.edit', compact('order', 'categories', 'colors', 'guests'));
    }

    public function update(Request $request, Order $order)
    {
        $validated = $this->validateOrder($request, $order);
        $validated['guest_name'] = Guest::findOrFail($validated['guest_id'])->name;

        if (
            $validated['status'] === 'Finishing'
            && $order->items()->where('status', 'Produksi')->exists()
        ) {
            throw ValidationException::withMessages([
                'status' => 'Order tidak bisa masuk Finishing karena masih ada produk dengan status Produksi.',
            ]);
        }

        if (
            $validated['status'] === 'Selesai'
            && collect($validated['items'])->contains(fn (array $item): bool => $item['status'] !== 'Ready')
        ) {
            throw ValidationException::withMessages([
                'status' => 'Order tidak bisa diselesaikan karena semua produk harus berstatus Ready.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $order) {

            $total = 0;

            $order->update([
                'order_id' => $validated['order_id'],
                'guest_id' => $validated['guest_id'],
                'guest_name' => $validated['guest_name'],
                'guest_source' => $validated['guest_source'],
                'phone' => $validated['phone'] ?? null,
                'deadline' => $validated['deadline'] ?? null,
                'last_edit' => now(),
                'status' => $validated['status'],
                'note' => $validated['note'] ?? null,
            ]);

            $this->rememberGuestPhone($validated['guest_id'], $validated['phone'] ?? null);

            $existingItemIds = [];

            foreach ($request->items as $index => $item) {

                $subtotal = $item['qty'] * $item['price'];

                $total += $subtotal;

                $itemId = $item['id'] ?? null;

                if ($itemId) {

                    $orderItem = $order->items()->findOrFail($itemId);

                    $imagePath = $orderItem->image;

                    if ($request->hasFile("items.$index.image")) {

                        if ($imagePath) {
                            Storage::disk('public')->delete($imagePath);
                        }

                        $imagePath = $request
                            ->file("items.$index.image")
                            ->store('orders/products', 'public');
                    }

                    $orderItem->update([
                        'product' => $item['product'],
                        'category_id' => $item['category_id'] ?? null,
                        'qty' => $item['qty'],
                        'price' => $item['price'],
                        'color' => $item['color'] ?? null,
                        'source' => $item['source'],
                        'status' => $item['status'],
                        'image' => $imagePath,
                    ]);

                    $existingItemIds[] = $orderItem->id;

                } else {

                    $imagePath = null;

                    if ($request->hasFile("items.$index.image")) {
                        $imagePath = $request
                            ->file("items.$index.image")
                            ->store('orders/products', 'public');
                    }

                    $newItem = $order->items()->create([
                        'product' => $item['product'],
                        'category_id' => $item['category_id'] ?? null,
                        'qty' => $item['qty'],
                        'price' => $item['price'],
                        'color' => $item['color'] ?? null,
                        'source' => $item['source'],
                        'status' => $item['status'],
                        'image' => $imagePath,
                    ]);

                    $existingItemIds[] = $newItem->id;
                }
            }

            $itemsToDelete = $order->items()
                ->whereNotIn('id', $existingItemIds)
                ->get();

            foreach ($itemsToDelete as $item) {

                if ($item->image) {
                    Storage::disk('public')->delete($item->image);
                }

                $item->delete();
            }

            $order->update([
                'total_order' => $total,
            ]);
        });

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil diperbarui.');
    }

    public function destroy(Order $order)
    {
        foreach ($order->items as $item) {

            if ($item->image) {
                Storage::disk('public')->delete($item->image);
            }
        }

        $order->delete();

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil dihapus.');
    }

    private function validateOrder(Request $request, ?Order $order = null): array
    {
        $validated = $request->validate([
            'order_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('orders', 'order_id')->ignore($order),
            ],
            'guest_id' => 'required|integer|exists:guests,id',
            'guest_name' => 'required|string|max:255',
            'guest_source' => 'required|in:Lokal,Online,Touris',
            'phone' => 'nullable|string|max:50',

            'deadline' => 'nullable|date',

            'status' => [
                'required',
                'in:None,Proses Produksi,Finishing,Selesai,Cancel',
            ],

            'note' => 'nullable|string',

            'items' => 'required|array|min:1',

            'items.*.id' => 'nullable|integer',

            'items.*.product' => 'required|string|max:255',

            'items.*.category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],

            'items.*.qty' => 'required|integer|min:1',

            'items.*.price' => 'required|numeric|min:0',

            'items.*.color' => 'nullable|string|max:255',

            'items.*.source' => [
                'required',
                'in:Stock,Order,Mix,Service',
            ],

            'items.*.status' => [
                'required',
                'in:None,Produksi,Distribusi,Ready',
            ],

            'items.*.image' => [
                'nullable',
                'image',
                'max:5120',
            ],
        ], [
            'order_id.unique' => 'Order ID sudah digunakan.',
        ]);

        return $validated;
    }

    private function guestOptions()
    {
        return Guest::with('phones')
            ->orderByRaw('LOWER(name)')
            ->get(['id', 'name']);
    }

    private function rememberGuestPhone(int $guestId, ?string $phone): void
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return;
        }

        GuestPhone::firstOrCreate([
            'guest_id' => $guestId,
            'phone' => $phone,
        ]);
    }
}
