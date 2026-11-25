<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductStockController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $unit = $user->activeBusinessUnit();
        abort_unless(
            $user->canAccessMenu('product_stock')
                && $unit !== null
                && $user->canAccessUnit($unit),
            403,
        );

        $products = Product::query()
            ->with([
                'category:id,name',
                'stockBalances' => fn ($query) => $query->whereIn('business_unit', [
                    User::UNIT_BALI,
                    User::UNIT_FACTORY,
                ]),
            ])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('product-stock.index', [
            'products' => $products,
            'showBaliPrice' => $unit === User::UNIT_BALI,
            'showFactoryPrice' => $unit === User::UNIT_FACTORY,
        ]);
    }

    public function add(Request $request, Product $product): RedirectResponse
    {
        $user = $request->user();
        $unit = $user->activeBusinessUnit();
        abort_unless(
            $user->canAccessMenu('product_stock')
                && $unit !== null
                && $user->canAccessUnit($unit),
            403,
        );

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        DB::transaction(function () use ($product, $user, $validated): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($lockedProduct->factory_sale_price === null) {
                throw ValidationException::withMessages([
                    'quantity' => 'Isi harga Factory di katalog sebelum mencatat stok produk.',
                ]);
            }

            $stock = ProductStock::query()
                ->where('product_id', $lockedProduct->id)
                ->where('business_unit', User::UNIT_FACTORY)
                ->lockForUpdate()
                ->first();
            $currentQuantity = (int) ($stock?->quantity ?? 0);
            $newQuantity = $currentQuantity + (int) $validated['quantity'];
            if ($newQuantity > 1000000) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah stok Factory tidak boleh melebihi 1.000.000 unit.',
                ]);
            }

            if ($stock === null) {
                ProductStock::create([
                    'product_id' => $lockedProduct->id,
                    'business_unit' => User::UNIT_FACTORY,
                    'quantity' => $newQuantity,
                    'updated_by_id' => $user->id,
                ]);

                return;
            }

            $stock->update([
                'quantity' => $newQuantity,
                'updated_by_id' => $user->id,
            ]);
        });

        return back()->with('success', "Stok {$product->name} berhasil ditambahkan ke Factory.");
    }

    public function transfer(Request $request, Product $product): RedirectResponse
    {
        $user = $request->user();
        $unit = $user->activeBusinessUnit();
        abort_unless(
            $user->canAccessMenu('product_stock')
                && $unit !== null
                && $user->canAccessUnit($unit),
            403,
        );

        $validated = $request->validate([
            'source_business_unit' => ['required', 'in:bali,factory'],
            'destination_business_unit' => ['required', 'in:bali,factory', 'different:source_business_unit'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        DB::transaction(function () use ($product, $user, $validated): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($lockedProduct->factory_sale_price === null) {
                throw ValidationException::withMessages([
                    'quantity' => 'Isi harga Factory di katalog sebelum memindahkan stok produk.',
                ]);
            }

            $source = ProductStock::query()
                ->where('product_id', $lockedProduct->id)
                ->where('business_unit', $validated['source_business_unit'])
                ->lockForUpdate()
                ->first();
            $quantity = (int) $validated['quantity'];
            if ($source === null || (int) $source->quantity < $quantity) {
                $sourceLabel = $validated['source_business_unit'] === User::UNIT_FACTORY ? 'Factory' : 'Bali';
                $availableQuantity = (int) ($source?->quantity ?? 0);

                throw ValidationException::withMessages([
                    'quantity' => "Saldo stok {$sourceLabel} hanya {$availableQuantity} unit; jumlah yang diminta {$quantity} unit.",
                ]);
            }

            $destination = ProductStock::query()
                ->where('product_id', $lockedProduct->id)
                ->where('business_unit', $validated['destination_business_unit'])
                ->lockForUpdate()
                ->first();
            $destinationQuantity = (int) ($destination?->quantity ?? 0);
            if ($destinationQuantity + $quantity > 1000000) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah stok pada lokasi tujuan tidak boleh melebihi 1.000.000 unit.',
                ]);
            }

            $source->update([
                'quantity' => (int) $source->quantity - $quantity,
                'updated_by_id' => $user->id,
            ]);

            if ($destination === null) {
                ProductStock::create([
                    'product_id' => $lockedProduct->id,
                    'business_unit' => $validated['destination_business_unit'],
                    'quantity' => $quantity,
                    'updated_by_id' => $user->id,
                ]);

                return;
            }

            $destination->update([
                'quantity' => $destinationQuantity + $quantity,
                'updated_by_id' => $user->id,
            ]);
        });

        return back()->with('success', "Stok {$product->name} berhasil dipindahkan.");
    }
}
