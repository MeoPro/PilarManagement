@extends('layouts.admin')

@section('title', 'Daftar Tamu')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pelanggan</p>
            <h1>Daftar Tamu</h1>
            <p>Data pelanggan dan ringkasan riwayat pembelian.</p>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error" role="alert">{{ session('error') }}</p>
    @endif

    <div class="actions">
        @if(auth()->user()->canAccessMenu('orders'))
            <a href="{{ route('orders.index') }}" class="btn">Kembali ke Order</a>
            <a href="{{ route('orders.create') }}" class="btn">+ Create Order</a>
        @endif
        @if(auth()->user()->canAccessMenu('popular_categories'))
            <a href="{{ route('reports.popular-categories') }}" class="btn">Kategori Terlaris</a>
        @endif
    </div>

    <section class="guest-summary-grid" aria-label="Ringkasan tamu">
        <article class="guest-debt-summary">
            <span>Total hutang dari order yang selesai</span>
            <strong>Rp {{ number_format($totalGuestDebt, 0, ',', '.') }}</strong>
        </article>
        <article class="guest-debt-summary">
            <span>Jumlah tamu berhutang</span>
            <strong>{{ number_format($indebtedGuestCount, 0, ',', '.') }} tamu</strong>
        </article>
        <article class="guest-debt-summary">
            <span>Total semua tamu</span>
            <strong>{{ number_format($totalGuestCount, 0, ',', '.') }} tamu</strong>
        </article>
    </section>

    <form action="{{ route('guests.index') }}" method="GET" class="search-form">
        <input
            type="search"
            name="search"
            value="{{ $search }}"
            placeholder="Cari nama tamu, telepon, atau SPK"
            aria-label="Cari tamu"
        >
        <select name="sort" aria-label="Urutkan tamu">
            @foreach($sortOptions as $value => $label)
                <option value="{{ $value }}" @selected($sort === $value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        <button type="submit" class="btn">Cari</button>
        @if($search !== '' || $sort !== 'alphabetical')
            <a href="{{ route('guests.index') }}" class="btn">Reset</a>
        @endif
    </form>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID Tamu</th>
                    <th>Tamu</th>
                    <th>Alamat</th>
                    <th>No. Telp</th>
                    <th>SPK</th>
                    <th>Jumlah Order</th>
                    <th>Total Belanja</th>
                    <th>Hutang</th>
                    <th>Sisa Pembayaran</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($guests as $guest)
                    <tr>
                        <td>{{ $guest->id }}</td>
                        <td><a href="{{ route('guests.show', $guest) }}">{{ $guest->name }}</a></td>
                        <td>{{ $guest->address ?? '-' }}</td>
                        <td>
                            @forelse($guest->phones as $guestPhone)
                                {{ $guestPhone->phone }}@if(!$loop->last), @endif
                            @empty
                                -
                            @endforelse
                        </td>
                        <td>
                            @foreach($guest->orders as $order)
                                @if(auth()->user()->canAccessMenu('orders'))
                                    <a href="{{ route('orders.show', $order) }}">{{ $order->order_id }}</a>
                                @else
                                    {{ $order->order_id }}
                                @endif
                                @if(!$loop->last), @endif
                            @endforeach
                        </td>
                        <td>{{ $guest->orders_count }}</td>
                        <td><a href="{{ route('guests.show', $guest) }}">Rp {{ number_format($guest->orders_sum_total_order ?? 0, 0, ',', '.') }}</a></td>
                        <td>
                            @if((float) $guest->debt_amount > 0)
                                <strong class="guest-debt-amount">Rp {{ number_format((float) $guest->debt_amount, 0, ',', '.') }}</strong>
                            @else
                                Rp 0
                            @endif
                        </td>
                        <td>
                            @if((float) $guest->unpaid_amount > 0)
                                <strong class="guest-debt-amount">Rp {{ number_format((float) $guest->unpaid_amount, 0, ',', '.') }}</strong>
                            @else
                                Rp 0
                            @endif
                        </td>
                        <td>
                            <div class="order-row-actions">
                                <a href="{{ route('guests.show', $guest) }}" class="icon-button" aria-label="Lihat tamu" title="Lihat tamu">
                                    <x-layout.icon name="eye" />
                                </a>
                                <a href="{{ route('guests.edit', $guest) }}" class="icon-button" aria-label="Edit tamu" title="Edit tamu">
                                    <x-layout.icon name="pencil" />
                                </a>
                                @if(!$guest->hasActiveOrders())
                                    <form action="{{ route('guests.destroy', $guest) }}" method="POST" onsubmit="return confirm('Hapus data tamu ini? Riwayat order akan tetap tersimpan.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="icon-button delete-button" aria-label="Hapus tamu {{ $guest->name }}" title="Hapus tamu">
                                            <x-layout.icon name="trash" />
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">Belum ada data tamu.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $guests->links() }}
@endsection
