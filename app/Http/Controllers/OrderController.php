<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::with('items.category')
            ->latest()
            ->paginate(10);

        return view('orders.index', compact('orders'));
    }

    public function create()
    {
        $categories = Category::orderBy('name')->get();

        return view('orders.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);

        DB::transaction(function () use ($request, $validated) {

            $total = 0;

            $order = Order::create([
                'order_id' => $validated['order_id'],
                'guest_name' => $validated['guest_name'],
                'phone' => $validated['phone'] ?? null,
                'total_order' => 0,
                'deadline' => $validated['deadline'] ?? null,
                'last_edit' => now(),
                'status' => $validated['status'],
                'note' => $validated['note'] ?? null,
            ]);

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

        return view('orders.edit', compact('order', 'categories'));
    }

    public function update(Request $request, Order $order)
    {
        $validated = $this->validateOrder($request);

        if (
            $validated['status'] === 'Finishing'
            && $order->items()->where('status', 'Produksi')->exists()
        ) {
            throw ValidationException::withMessages([
                'status' => 'Order tidak bisa masuk Finishing karena masih ada produk dengan status Produksi.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $order) {

            $total = 0;

            $order->update([
                'order_id' => $validated['order_id'],
                'guest_name' => $validated['guest_name'],
                'phone' => $validated['phone'] ?? null,
                'deadline' => $validated['deadline'] ?? null,
                'last_edit' => now(),
                'status' => $validated['status'],
                'note' => $validated['note'] ?? null,
            ]);

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

    private function validateOrder(Request $request): array
    {
        $validated = $request->validate([
            'order_id' => 'required|string|max:255',
            'guest_name' => 'required|string|max:255',
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
                'in:Stock,Order,Mix',
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
        ]);

        return $validated;
    }
}