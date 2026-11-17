@extends('layouts.admin')

@section('title', 'Kategori Terlaris')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Laporan</p>
            <h1>Kategori Produk Terlaris</h1>
            <p>Diurutkan berdasarkan jumlah unit produk pada order yang tidak dibatalkan.</p>
        </div>
    </div>

    <nav class="actions">
        @if(auth()->user()->canAccessMenu('categories'))
            <a class="btn" href="{{ route('categories.index') }}">Kelola Kategori</a>
        @endif
        @if(auth()->user()->canAccessMenu('orders'))
            <a class="btn" href="{{ route('orders.index') }}">Daftar Order</a>
        @endif
    </nav>

    <div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th class="rank">Peringkat</th>
                <th>Kategori Produk</th>
                <th>Jumlah Terjual</th>
            </tr>
        </thead>
        <tbody>
            @forelse($categories as $category)
                <tr>
                    <td class="rank">{{ $loop->iteration }}</td>
                    <td>{{ $category->name }}</td>
                    <td>{{ number_format($category->units_sold, 0, ',', '.') }} unit</td>
                </tr>
            @empty
                <tr><td colspan="3">Belum ada produk berkategori yang dibeli.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
@endsection
