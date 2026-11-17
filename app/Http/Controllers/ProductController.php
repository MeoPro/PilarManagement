<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\View\View;
use RuntimeException;

class ProductController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
            ->with('category:id,name')
            ->orderBy('name')
            ->paginate(20);

        return view('products.index', compact('products'));
    }

    public function create(): View
    {
        return view('products.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizePrice($request);
        $this->normalizeDimensions($request);
        $validated = $request->validate($this->validationRules());
        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('catalog/products', 'public');
            if (! is_string($imagePath)) {
                throw new RuntimeException('Foto produk gagal disimpan.');
            }
        }

        Product::create([
            'name' => trim($validated['name']),
            'category_id' => $validated['category_id'],
            'price' => $validated['price'],
            'dimensions' => $validated['dimensions'],
            'colors' => $this->normalizeColors($validated['colors'] ?? []),
            'image' => $imagePath,
        ]);

        return redirect()
            ->route('products.index')
            ->with('success', 'Produk katalog berhasil ditambahkan.');
    }

    public function edit(Product $product): View
    {
        return view('products.edit', [
            ...$this->formData(),
            'product' => $product,
        ]);
    }

    public function show(Product $product): View
    {
        $product->load('category:id,name');

        return view('products.show', compact('product'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->normalizePrice($request);
        $this->normalizeDimensions($request);
        $validated = $request->validate($this->validationRules($product));
        $imagePath = $product->image;
        $previousImagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('catalog/products', 'public');
            if (! is_string($imagePath)) {
                throw new RuntimeException('Foto produk gagal disimpan.');
            }

            $previousImagePath = $product->image;
        }

        $product->update([
            'name' => trim($validated['name']),
            'category_id' => $validated['category_id'],
            'price' => $validated['price'],
            'dimensions' => $validated['dimensions'],
            'colors' => $this->normalizeColors($validated['colors'] ?? []),
            'image' => $imagePath,
        ]);

        if ($previousImagePath !== null) {
            Storage::disk('public')->delete($previousImagePath);
        }

        return redirect()
            ->route('products.index')
            ->with('success', 'Produk katalog berhasil diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $imagePath = $product->image;
        $product->delete();

        if ($imagePath !== null) {
            Storage::disk('public')->delete($imagePath);
        }

        return redirect()
            ->route('products.index')
            ->with('success', 'Produk katalog berhasil dihapus.');
    }

    /**
     * @return array{categories: \Illuminate\Database\Eloquent\Collection<int, Category>, colors: Collection<int, string>}
     */
    private function formData(): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'colors' => Color::query()->orderBy('name')->pluck('name'),
        ];
    }

    /**
     * @return array<string, array<int, string|Unique>>
     */
    private function validationRules(?Product $product = null): array
    {
        $nameRule = Rule::unique('products', 'name');
        if ($product !== null) {
            $nameRule->ignore($product);
        }

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                $nameRule,
            ],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'dimensions' => ['required', 'string', 'max:100', 'regex:'.Product::DIMENSIONS_PATTERN],
            'colors' => ['nullable', 'array', 'max:2'],
            'colors.*' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:5120'],
        ];
    }

    private function normalizePrice(Request $request): void
    {
        $price = $request->input('price');

        if (! is_string($price)) {
            return;
        }

        $price = trim($price);

        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $price) === 1) {
            $request->merge(['price' => str_replace(',', '', $price)]);
        }
    }

    private function normalizeDimensions(Request $request): void
    {
        $dimensions = $request->input('dimensions');

        if (is_string($dimensions)) {
            $request->merge([
                'dimensions' => Product::normalizeDimensions($dimensions),
            ]);
        }
    }

    /**
     * @param  array<int, string|null>  $colors
     * @return list<string>
     */
    private function normalizeColors(array $colors): array
    {
        $normalizedColors = [];

        foreach ($colors as $color) {
            $color = trim((string) $color);
            if ($color === '') {
                continue;
            }

            $key = mb_strtolower($color);
            $normalizedColors[$key] ??= $color;
        }

        return array_values($normalizedColors);
    }
}
