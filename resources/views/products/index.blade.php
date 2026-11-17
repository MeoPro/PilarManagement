@extends('layouts.admin')

@section('title', 'Katalog Produk')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Master data</p>
            <h1>Katalog Produk</h1>
            <p>Produk katalog dapat dipilih saat membuat order untuk mengisi detailnya otomatis.</p>
        </div>
        <a href="{{ route('products.create') }}" class="btn btn-primary">+ Tambah Produk</a>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Foto</th>
                    <th>Produk</th>
                    <th>Kategori</th>
                    <th>Ukuran</th>
                    <th>Warna</th>
                    <th>Harga</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                    <tr>
                        <td>{{ $product->sku ?? '—' }}</td>
                        <td>
                            @if($product->image)
                                <img
                                    class="product-catalog-photo"
                                    src="{{ asset('storage/'.$product->image) }}"
                                    alt="Foto {{ $product->name }}"
                                    loading="lazy"
                                >
                            @else
                                <span class="muted">Belum ada foto</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
                        </td>
                        <td>{{ $product->category?->name ?? '—' }}</td>
                        <td>{{ $product->dimensions ?? '—' }}</td>
                        <td>{{ implode(', ', $product->colors ?? []) ?: '—' }}</td>
                        <td>Rp {{ number_format((float) $product->price, 0, '.', ',') }}</td>
                        <td>
                            <a
                                href="{{ route('products.edit', $product) }}"
                                class="btn btn-warning"
                                aria-label="Ubah {{ $product->name }}"
                            >
                                <x-layout.icon name="pencil" />
                            </a>
                            <form
                                action="{{ route('products.destroy', $product) }}"
                                method="POST"
                                class="inline-form"
                                onsubmit="return confirm('Hapus produk katalog ini?')"
                            >
                                @csrf
                                @method('DELETE')
                                <button
                                    type="submit"
                                    class="btn btn-danger icon-button"
                                    aria-label="Hapus {{ $product->name }}"
                                    title="Hapus produk"
                                >
                                    <x-layout.icon name="trash" />
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">Belum ada produk katalog.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $products->links() }}
@endsection
