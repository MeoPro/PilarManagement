@extends('layouts.admin')

@section('title', 'Ubah Produk Katalog')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Master data</p>
            <h1>Ubah Produk Katalog</h1>
            <p>Perbarui detail produk {{ $product->name }}.</p>
        </div>
    </div>

    @include('products.form')
@endsection
