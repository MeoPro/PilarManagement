@extends('layouts.admin')

@section('title', 'Tambah Produk Katalog')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Master data</p>
            <h1>Tambah Produk Katalog</h1>
            <p>Isi detail produk yang akan digunakan kembali saat membuat order.</p>
        </div>
    </div>

    @include('products.form')
@endsection
