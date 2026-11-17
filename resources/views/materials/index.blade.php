@extends('layouts.admin')

@section('title', 'Material')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>Material</h1>
            <p>Saldo stok dihitung otomatis dari seluruh transaksi masuk dan keluar.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('materials.create') }}">+ Tambah Material</a>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    @if(auth()->user()->isOwner())
        <form action="{{ route('materials.index') }}" method="GET" class="search-form orders-filter-form">
            <select name="business_unit" aria-label="Filter unit">
                <option value="">Semua Unit</option>
                @foreach($unitLabels as $unit => $label)
                    <option value="{{ $unit }}" @selected($selectedUnit === $unit)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn">Filter</button>
        </form>
    @endif

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nama Material</th>
                    @if(auth()->user()->isOwner())
                        <th>Unit</th>
                    @endif
                    <th>Saldo</th>
                    <th>Harga Satuan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($materials as $material)
                    <tr>
                        <td>{{ $material->name }}<small>{{ $material->unit ?? 'Satuan belum ditentukan' }}</small></td>
                        @if(auth()->user()->isOwner())
                            <td>{{ $unitLabels[$material->business_unit] ?? $material->business_unit }}</td>
                        @endif
                        <td>{{ number_format($material->current_stock, 3, ',', '.') }} {{ $material->unit }}</td>
                        <td>Rp {{ number_format((float) $material->unit_price, 2, ',', '.') }}</td>
                        <td>
                            <div class="order-row-actions">
                                <a class="icon-button" href="{{ route('materials.show', $material) }}" aria-label="Lihat {{ $material->name }}" title="Lihat detail">
                                    <x-layout.icon name="eye" />
                                </a>
                                <a class="icon-button" href="{{ route('materials.edit', $material) }}" aria-label="Edit {{ $material->name }}" title="Edit">
                                    <x-layout.icon name="pencil" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ auth()->user()->isOwner() ? 5 : 4 }}">Belum ada data material.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $materials->links() }}
@endsection
