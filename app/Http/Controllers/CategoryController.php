<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()
            ->orderByRaw('LOWER(name)')
            ->orderBy('id')
            ->get();

        return view('categories.index', compact('categories'));
    }

    public function popularProducts(): View
    {
        $purchasedItems = fn (Builder $query): Builder => $query->whereHas(
            'order',
            fn (Builder $orderQuery): Builder => $orderQuery->where('status', '!=', 'Cancel')
        );

        $categories = Category::query()
            ->whereHas('orderItems', $purchasedItems)
            ->withSum(['orderItems as units_sold' => $purchasedItems], 'qty')
            ->orderByDesc('units_sold')
            ->orderBy('name')
            ->get();

        return view('categories.popular', compact('categories'));
    }

    public function create()
    {
        return view('categories.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
        ]);

        $category = Category::create($validated);

        // Untuk AJAX dari Order
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Category berhasil ditambahkan.',
                'category' => $category,
            ]);
        }

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category berhasil ditambahkan.');
    }

    public function edit(Category $category)
    {
        return view('categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,'.$category->id,
        ]);

        $category->update($validated);

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category berhasil diperbarui.');
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category berhasil dihapus.');
    }
}
