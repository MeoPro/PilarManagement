@extends('layouts.admin')

@section('title', 'Detail Pengeluaran')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional / Pengeluaran</p>
            <h1>{{ $expense->description }}</h1>
            <p>{{ $expense->business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }} · {{ $expense->occurred_at->format('d/m/Y') }}</p>
        </div>
        <div class="order-list-header-actions">
            <a class="btn" href="{{ route('expenses.index') }}">Kembali</a>
            <a class="btn btn-primary" href="{{ route('expenses.edit', $expense) }}">Edit</a>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    <section class="card">
        <p><strong>Kategori:</strong> {{ $expense->category }}</p>
        <p><strong>Jumlah:</strong> {{ number_format((float) $expense->quantity, 3, ',', '.') }} {{ $expense->unit }}</p>
        <p><strong>Harga satuan:</strong> Rp {{ number_format((float) $expense->unit_price, 2, ',', '.') }}</p>
        <p><strong>Total:</strong> Rp {{ number_format((float) $expense->total, 2, ',', '.') }}</p>
        <p><strong>Catatan:</strong> {{ $expense->note ?? '—' }}</p>
        <form action="{{ route('expenses.destroy', $expense) }}" method="POST" onsubmit="return confirm('Hapus catatan pengeluaran ini?')">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger icon-button" type="submit" aria-label="Hapus pengeluaran" title="Hapus">
                <x-layout.icon name="trash" />
            </button>
        </form>
    </section>
@endsection
