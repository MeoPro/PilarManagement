<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
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
        $mockupPaths = $this->storeMockups($this->mockupFilesFromRequest($request));
        $sku = trim((string) ($validated['sku'] ?? ''));

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('catalog/products', 'public');
            if (! is_string($imagePath)) {
                throw new RuntimeException('Foto produk gagal disimpan.');
            }
        }

        Product::create([
            'name' => trim($validated['name']),
            'sku' => $sku === '' ? null : $sku,
            'category_id' => $validated['category_id'],
            'price' => $validated['price'],
            'dimensions' => $validated['dimensions'],
            'colors' => $this->normalizeColors($validated['colors'] ?? []),
            'image' => $imagePath,
            'mockup' => $mockupPaths[0] ?? null,
            'mockups' => $mockupPaths ?: null,
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
        $validated = $request->validate($this->validationRules());
        $imagePath = $product->image;
        $previousImagePath = null;
        $mockupPaths = $product->mockupPaths();
        $previousMockupPaths = [];
        $sku = trim((string) ($validated['sku'] ?? ''));

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('catalog/products', 'public');
            if (! is_string($imagePath)) {
                throw new RuntimeException('Foto produk gagal disimpan.');
            }

            $previousImagePath = $product->image;
        }

        if ($request->hasFile('mockups')) {
            $previousMockupPaths = $product->mockupPaths();
            $mockupPaths = $this->storeMockups($this->mockupFilesFromRequest($request));
        }

        $product->update([
            'name' => trim($validated['name']),
            'sku' => array_key_exists('sku', $validated)
                ? ($sku === '' ? null : $sku)
                : $product->sku,
            'category_id' => $validated['category_id'],
            'price' => $validated['price'],
            'dimensions' => $validated['dimensions'],
            'colors' => $this->normalizeColors($validated['colors'] ?? []),
            'image' => $imagePath,
            'mockup' => $mockupPaths[0] ?? null,
            'mockups' => $mockupPaths ?: null,
        ]);

        if ($previousImagePath !== null) {
            Storage::disk('public')->delete($previousImagePath);
        }

        foreach ($previousMockupPaths as $previousMockupPath) {
            Storage::disk('public')->delete($previousMockupPath);
        }

        return redirect()
            ->route('products.index')
            ->with('success', 'Produk katalog berhasil diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $imagePath = $product->image;
        $mockupPaths = $product->mockupPaths();
        $product->delete();

        if ($imagePath !== null) {
            Storage::disk('public')->delete($imagePath);
        }

        foreach ($mockupPaths as $mockupPath) {
            Storage::disk('public')->delete($mockupPath);
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
     * @return array<string, array<int, string>>
     */
    private function validationRules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'sku' => ['nullable', 'string', 'max:100'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'dimensions' => ['required', 'string', 'max:100', 'regex:'.Product::DIMENSIONS_PATTERN],
            'colors' => ['nullable', 'array', 'max:2'],
            'colors.*' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:5120'],
            'mockups' => ['nullable', 'array', 'max:10'],
            'mockups.*' => ['image', 'max:5120'],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    private function mockupFilesFromRequest(Request $request): array
    {
        $mockupFiles = $request->file('mockups', []);
        $mockupFiles = is_array($mockupFiles) ? $mockupFiles : [$mockupFiles];

        return array_values(array_filter(
            $mockupFiles,
            fn ($mockupFile): bool => $mockupFile instanceof UploadedFile,
        ));
    }

    /**
     * @param  list<UploadedFile>  $mockupFiles
     * @return list<string>
     */
    private function storeMockups(array $mockupFiles): array
    {
        $mockupPaths = [];

        foreach ($mockupFiles as $mockupFile) {
            $mockupPath = $mockupFile->store('catalog/mockups', 'public');
            if (! is_string($mockupPath)) {
                throw new RuntimeException('Mockup produk gagal disimpan.');
            }

            $mockupPaths[] = $mockupPath;
        }

        return $mockupPaths;
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
