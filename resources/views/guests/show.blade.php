@extends('layouts.admin')

@section('title', 'Profil Tamu')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pelanggan</p>
            <h1>Profil Tamu</h1>
            <p>Informasi pelanggan dan ringkasan transaksi.</p>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    <nav class="actions">
        <a class="btn" href="{{ route('guests.index') }}">Kembali ke Daftar Tamu</a>
        <a class="btn" href="{{ route('guests.edit', $guest) }}">Edit Tamu</a>
        @if(auth()->user()->canAccessMenu('orders'))
            <a class="btn" href="{{ route('orders.create') }}">+ Buat Order</a>
        @endif
    </nav>

    <section class="profile">
        <h2>{{ $guest->name }}</h2>
        <p><strong>Alamat:</strong> {{ $guest->address ?? '-' }}</p>
        <p>
            <strong>No. Telepon:</strong>
            @forelse($guest->phones as $guestPhone)
                {{ $guestPhone->phone }}@if(!$loop->last), @endif
            @empty
                -
            @endforelse
        </p>
        <p class="spending">
            Total belanja:
            Rp {{ number_format($guest->orders_sum_total_order ?? 0, 0, ',', '.') }}
        </p>
        <p><strong>Jumlah order:</strong> {{ $guest->orders_count }} kali</p>
        <p><strong>Hutang order selesai:</strong> Rp {{ number_format((float) $guest->debt_amount, 0, ',', '.') }}</p>
        <small>Total tidak memasukkan order yang dibatalkan.</small>
    </section>

    <h2>Riwayat Pembelian</h2>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>SPK</th>
                    <th>Jenis Order</th>
                    <th>Produk yang Dibeli</th>
                    <th>Total</th>
                    <th>DP / Dibayar</th>
                    <th>Sisa</th>
                    <th>Status</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td>{{ $order->created_at->format('d/m/Y') }}</td>
                        <td>{{ $order->order_id }}</td>
                        <td>{{ $order->order_type ?? '-' }}</td>
                        <td>
                            <ul class="items">
                                @forelse($order->items as $item)
                                    <li>
                                        {{ $item->product }} × {{ $item->qty }}
                                        @if($item->category)
                                            ({{ $item->category->name }})
                                        @endif
                                        — Rp {{ number_format($item->price, 0, ',', '.') }}/unit
                                    </li>
                                @empty
                                    <li>Detail produk tidak tersedia.</li>
                                @endforelse
                            </ul>
                        </td>
                        <td>Rp {{ number_format($order->total_order, 0, ',', '.') }}</td>
                        <td>{{ number_format($order->paid_percentage, 1, ',', '.') }}% · Rp {{ number_format($order->paid_amount, 0, ',', '.') }}</td>
                        <td>
                            Rp {{ number_format($order->amount_remaining, 0, ',', '.') }}
                            @if($order->is_debt)
                                <strong class="guest-debt-amount">Hutang</strong>
                            @elseif($order->is_paid)
                                Lunas
                            @else
                                Belum lunas
                            @endif
                        </td>
                        <td>{{ $order->status }}</td>
                        <td>
                            @if(auth()->user()->canAccessMenu('orders'))
                                <a href="{{ route('orders.show', $order) }}">Lihat Order</a>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">Belum ada riwayat pembelian.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $orders->links() }}
@endsection
