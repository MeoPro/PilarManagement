@extends('layouts.admin')

@section('title', 'Detail Produk')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Katalog Produk</p>
            <h1>Detail Produk</h1>
            <p>Informasi produk {{ $product->name }}.</p>
        </div>
        <div class="actions">
            <a href="{{ route('products.edit', $product) }}" class="btn btn-primary">Edit Produk</a>
            <a href="{{ route('products.index') }}" class="btn">Kembali ke Katalog</a>
        </div>
    </div>

    <section class="card compact-form">
        @if($product->image)
            <img
                class="product-catalog-photo product-catalog-photo-large"
                src="{{ asset('storage/'.$product->image) }}"
                alt="Foto {{ $product->name }}"
            >
        @endif
        <div>
            <strong>Nama:</strong>
            <span>{{ $product->name }}</span>
        </div>
        <div>
            <strong>Kategori:</strong>
            <span>{{ $product->category?->name ?? '—' }}</span>
        </div>
        <div>
            <strong>Harga:</strong>
            <span>Rp {{ number_format((float) $product->price, 0, ',', '.') }}</span>
        </div>
        <div>
            <strong>Ukuran (P x L x T):</strong>
            <span>{{ $product->dimensions ?? '—' }}</span>
        </div>
        <div>
            <strong>Warna:</strong>
            <span>{{ implode(', ', $product->colors ?? []) ?: '—' }}</span>
        </div>
    </section>
@endsection
