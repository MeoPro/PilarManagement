<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    /**
     * Display a listing of orders.
     */
    public function index()
    {
        $orders = Order::latest()->paginate(10);

        return view('orders.index', compact('orders'));
    }

    /**
     * Show the form for creating a new order.
     */
    public function create()
    {
        return view('orders.create');
    }

    /**
     * Store a newly created order.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string|max:255|unique:orders,order_id',
            'guest_name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',

            'items' => 'nullable|array',
            'items.*.name' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|integer|min:1',

            'color' => 'nullable|string|max:255',
            'image_date' => 'nullable|date',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'deadline' => 'nullable|date',
            'urgent' => 'nullable|boolean',

            'status' => 'required|in:Pending,Design,Production,Finishing,Ready,Completed,Cancelled',

            'note' => 'nullable|string',
        ]);

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')
                ->store('orders', 'public');
        }

        $validated['urgent'] = $request->boolean('urgent');

        Order::create($validated);

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil dibuat.');
    }

    /**
     * Display the specified order.
     */
    public function show(Order $order)
    {
        return view('orders.show', compact('order'));
    }

    /**
     * Show the form for editing the specified order.
     */
    public function edit(Order $order)
    {
        return view('orders.edit', compact('order'));
    }

    /**
     * Update the specified order.
     */
    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'order_id' => 'required|string|max:255|unique:orders,order_id,' . $order->id,
            'guest_name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',

            'items' => 'nullable|array',
            'items.*.name' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|integer|min:1',

            'color' => 'nullable|string|max:255',
            'image_date' => 'nullable|date',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'deadline' => 'nullable|date',
            'urgent' => 'nullable|boolean',

            'status' => 'required|in:Pending,Design,Production,Finishing,Ready,Completed,Cancelled',

            'note' => 'nullable|string',
        ]);

        if ($request->hasFile('image')) {

            if ($order->image) {
                Storage::disk('public')->delete($order->image);
            }

            $validated['image'] = $request->file('image')
                ->store('orders', 'public');
        }

        $validated['urgent'] = $request->boolean('urgent');

        $order->update($validated);

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil diperbarui.');
    }

    /**
     * Remove the specified order.
     */
    public function destroy(Order $order)
    {
        if ($order->image) {
            Storage::disk('public')->delete($order->image);
        }

        $order->delete();

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil dihapus.');
    }
}