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
        <div>
            <strong>Foto Produk:</strong>
            @if($product->image)
                <p>
                    <img
                        class="product-catalog-photo product-catalog-photo-large"
                        src="{{ asset('storage/'.$product->image) }}"
                        alt="Foto produk {{ $product->name }}"
                    >
                </p>
            @else
                <span>Belum ada foto produk</span>
            @endif
        </div>
        <div>
            <strong>Foto Mockup:</strong>
            @if($product->mockupPaths() !== [])
                <div class="order-mockup-gallery">
                    @foreach($product->mockupPaths() as $mockupPath)
                    <img
                        class="product-catalog-photo product-catalog-photo-large"
                        src="{{ asset('storage/'.$mockupPath) }}"
                        alt="Mockup {{ $product->name }}"
                    >
                    @endforeach
                </div>
            @else
                <span>Belum ada mockup</span>
            @endif
        </div>
        <div>
            <strong>Nama:</strong>
            <span>{{ $product->name }}</span>
        </div>
        <div>
            <strong>SKU:</strong>
            <span>{{ $product->sku ?? '—' }}</span>
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
