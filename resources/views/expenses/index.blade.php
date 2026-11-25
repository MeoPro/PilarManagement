@extends('layouts.admin')

@section('title', 'Pengeluaran')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>Pengeluaran</h1>
            <p>Catat biaya harian dengan total yang dihitung dari jumlah × harga satuan.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('expenses.create') }}">+ Tambah Pengeluaran</a>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    <form action="{{ route('expenses.index') }}" method="GET" class="search-form orders-filter-form">
        <label>Tanggal Mulai <input type="date" name="from" value="{{ $fromDate }}"></label>
        <label>Sampai <input type="date" name="to" value="{{ $toDate }}"></label>
        <label>
            Kategori
            <select name="category">
                <option value="">Semua kategori</option>
                @foreach($categories as $category)
                    <option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn">Filter</button>
    </form>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Kategori</th>
                    <th>Deskripsi</th>
                    @if(auth()->user()->isOwner())
                        <th>Unit</th>
                    @endif
                    <th>Jumlah</th>
                    <th>Harga Satuan</th>
                    <th>Total</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($expenses as $expense)
                    <tr>
                        <td>{{ $expense->occurred_at->format('d/m/Y') }}</td>
                        <td>{{ $expense->category }}</td>
                        <td>{{ $expense->description }}<small>{{ $expense->note ?? '' }}</small></td>
                        @if(auth()->user()->isOwner())
                            <td>{{ $unitLabels[$expense->business_unit] ?? $expense->business_unit }}</td>
                        @endif
                        <td>{{ number_format((float) $expense->quantity, 3, ',', '.') }} {{ $expense->unit }}</td>
                        <td>Rp {{ number_format((float) $expense->unit_price, 2, ',', '.') }}</td>
                        <td>Rp {{ number_format((float) $expense->total, 2, ',', '.') }}</td>
                        <td>
                            <div class="order-row-actions">
                                <a class="btn" href="{{ route('expenses.show', $expense) }}">Detail</a>
                                <a class="btn" href="{{ route('expenses.edit', $expense) }}">Edit</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ auth()->user()->isOwner() ? 8 : 7 }}">Belum ada pengeluaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $expenses->links() }}
@endsection
