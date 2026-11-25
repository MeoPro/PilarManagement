@extends('layouts.admin')

@section('title', 'Stock Produk')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>Stock Produk</h1>
            <p>Stok baru otomatis masuk ke Factory. Gunakan Pindahkan Lokasi untuk memindahkan unit antara Factory dan Bali.</p>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif
    @if($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Kategori</th>
                    <th>Ukuran</th>
                    @if($showBaliPrice || $showFactoryPrice)
                        <th>Harga</th>
                    @endif
                    <th>Stok Factory</th>
                    <th>Stok Bali</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                    @php($baliStock = $product->stockBalances->firstWhere('business_unit', \App\Models\User::UNIT_BALI))
                    @php($factoryStock = $product->stockBalances->firstWhere('business_unit', \App\Models\User::UNIT_FACTORY))
                    @php($defaultStockSource = (int) ($factoryStock?->quantity ?? 0) > 0 ? 'factory' : 'bali')
                    <tr>
                        <td>
                            <strong>{{ $product->name }}</strong>
                            @if($product->sku)
                                <small>{{ $product->sku }}</small>
                            @endif
                        </td>
                        <td>{{ $product->category?->name ?? '—' }}</td>
                        <td>{{ $product->dimensions ?? '—' }}</td>
                        @if($showBaliPrice)
                            <td>
                                Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                            </td>
                        @elseif($showFactoryPrice)
                            <td>
                                @if($product->factory_sale_price !== null)
                                    Rp {{ number_format((float) $product->factory_sale_price, 0, ',', '.') }}
                                @else
                                    <span class="muted">Belum diisi</span>
                                @endif
                            </td>
                        @endif
                        <td>{{ $factoryStock?->quantity ?? 0 }} unit</td>
                        <td>{{ $baliStock?->quantity ?? 0 }} unit</td>
                        <td>
                            <form
                                action="{{ route('product-stock.add', $product) }}"
                                method="POST"
                                class="product-stock-quantity-form"
                            >
                                @csrf
                                <label class="sr-only" for="stock-quantity-{{ $product->id }}">
                                    Jumlah stok baru {{ $product->name }} untuk Factory
                                </label>
                                <input
                                    id="stock-quantity-{{ $product->id }}"
                                    type="number"
                                    name="quantity"
                                    min="1"
                                    max="1000000"
                                    step="1"
                                    required
                                >
                                <button type="submit" class="btn">Tambah stok</button>
                            </form>
                            <details>
                                <summary class="btn">Pindahkan lokasi</summary>
                                <form
                                    action="{{ route('product-stock.transfer', $product) }}"
                                    method="POST"
                                    class="product-stock-quantity-form"
                                >
                                    @csrf
                                    <label for="stock-source-{{ $product->id }}">Dari</label>
                                    <select id="stock-source-{{ $product->id }}" name="source_business_unit" required>
                                        <option value="factory" @selected($defaultStockSource === 'factory')>
                                            Factory · saldo {{ $factoryStock?->quantity ?? 0 }} unit
                                        </option>
                                        <option value="bali" @selected($defaultStockSource === 'bali')>
                                            Bali · saldo {{ $baliStock?->quantity ?? 0 }} unit
                                        </option>
                                    </select>
                                    <label for="stock-destination-{{ $product->id }}">Ke</label>
                                    <select id="stock-destination-{{ $product->id }}" name="destination_business_unit" required>
                                        <option value="bali" @selected($defaultStockSource === 'factory')>Bali</option>
                                        <option value="factory" @selected($defaultStockSource === 'bali')>Factory</option>
                                    </select>
                                    <label class="sr-only" for="stock-transfer-quantity-{{ $product->id }}">
                                        Jumlah {{ $product->name }} yang dipindahkan
                                    </label>
                                    <input
                                        id="stock-transfer-quantity-{{ $product->id }}"
                                        type="number"
                                        name="quantity"
                                        min="1"
                                        max="1000000"
                                        step="1"
                                        required
                                    >
                                    <button type="submit" class="btn">Pindahkan</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 6 + (int) $showBaliPrice + (int) $showFactoryPrice }}">Belum ada produk katalog.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $products->links() }}
@endsection
