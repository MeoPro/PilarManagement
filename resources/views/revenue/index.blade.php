@extends('layouts.admin')

@section('title', 'Omzet')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Finance · {{ $businessUnitLabel }}</p>
            <h1>Omzet dan Arus Kas</h1>
            <p>
                @if($isFactoryUnit)
                    Omzet PF dicatat saat produk diserahkan ke PB; uang masuk tetap mengikuti tanggal pembayaran diterima.
                @else
                    Pemasukan dicatat menurut tanggal pembayaran diterima, bukan tanggal order dibuat.
                @endif
            </p>
        </div>
        @if(auth()->user()->canAccessMenu('expenses'))
            <a class="btn btn-primary" href="{{ route('expenses.create') }}">+ Catat Pengeluaran</a>
        @endif
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    <form action="{{ route('revenue.index') }}" method="GET" class="search-form orders-filter-form revenue-filter-form">
        <label for="revenue-from">Tanggal mulai
            <input id="revenue-from" type="date" name="from" value="{{ $fromDate }}">
        </label>
        <label for="revenue-to">Sampai
            <input id="revenue-to" type="date" name="to" value="{{ $toDate }}">
        </label>
        <button type="submit" class="btn">Terapkan</button>
    </form>

    @if($openingPaidAmount > 0)
        <div class="revenue-opening-note" role="note">
            <strong>Saldo DP lama tanpa tanggal: Rp {{ number_format($openingPaidAmount, 0, ',', '.') }}</strong>
            <span>Nilai ini tetap dihitung sebagai pembayaran pada order, tetapi tidak dimasukkan ke omzet pada rentang tanggal di atas.</span>
        </div>
    @endif

    <section class="revenue-summary-grid" aria-label="Ringkasan keuangan">
        @if($isFactoryUnit)
            <article class="revenue-summary-card is-monthly-orders">
                <span>Omzet PF ke PB</span>
                <strong>Rp {{ number_format($factorySaleTotal, 0, ',', '.') }}</strong>
                <small>Harga jual per unit × jumlah produk yang diserahkan</small>
            </article>
        @else
            <article class="revenue-summary-card is-monthly-orders">
                <span>Total order bulan ini</span>
                <strong>Rp {{ number_format($monthlyOrderTotal, 0, ',', '.') }}</strong>
                <small>Nilai order dibuat bulan ini, tidak termasuk order batal</small>
            </article>
        @endif
        <article class="revenue-summary-card is-incoming">
            <span>Total uang masuk</span>
            <strong>Rp {{ number_format($totalIncoming, 0, ',', '.') }}</strong>
            <small>Termasuk pengembalian cashbond Rp {{ number_format($cashbondRepaymentTotal, 0, ',', '.') }}</small>
        </article>
        <article class="revenue-summary-card is-outgoing">
            <span>Total uang keluar</span>
            <strong>Rp {{ number_format($totalOutgoing, 0, ',', '.') }}</strong>
            <small>Termasuk pembelian material, pengeluaran, gaji bersih, dan uang muka cashbond</small>
        </article>
        @if($isFactoryUnit)
            <article class="revenue-summary-card is-net">
                <span>Hasil bersih PF</span>
                <strong>Rp {{ number_format($factoryNetRevenue, 0, ',', '.') }}</strong>
                <small>Omzet PF ke PB dikurangi seluruh uang keluar pada rentang ini</small>
            </article>
        @endif
        <article class="revenue-summary-card is-cash-net">
            <span>Arus bersih</span>
            <strong>Rp {{ number_format($netCashFlow, 0, ',', '.') }}</strong>
            <small>Uang masuk dikurangi uang keluar pada rentang ini</small>
        </article>
    </section>

    <section class="revenue-outgoing-breakdown" aria-label="Rincian uang keluar">
        <strong>Rincian uang keluar</strong>
        <span>Pengeluaran Rp {{ number_format($expenseTotal, 0, ',', '.') }}</span>
        <span>Pembelian material Rp {{ number_format($materialPurchaseTotal, 0, ',', '.') }}</span>
        <span>Gaji bersih Rp {{ number_format($salaryTotal, 0, ',', '.') }}</span>
        <span>Uang muka cashbond Rp {{ number_format($cashbondAdvanceTotal, 0, ',', '.') }}</span>
    </section>

    <section class="card revenue-transactions-card">
        <div class="revenue-transactions-heading">
            <div>
                <h2>Detail transaksi</h2>
                <p>Pembayaran, omzet PF, dan pengeluaran sesuai tanggal transaksi.</p>
            </div>
            <span>{{ $transactions->total() }} transaksi</span>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Jenis</th>
                        <th>Detail</th>
                        <th>Catatan</th>
                        <th>Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($transaction->transaction_date, auth()->user()->timezone())->format('d/m/Y') }}</td>
                            <td>
                                <span class="revenue-direction-badge {{ $transaction->direction === 'Masuk' ? 'is-incoming' : ($transaction->direction === 'Omzet' ? 'is-revenue' : 'is-outgoing') }}">
                                    {{ $transaction->direction }} · {{ $transaction->category }}
                                </span>
                            </td>
                            <td>
                                @if((int) $transaction->order_pk > 0)
                                    <a href="{{ route('orders.show', $transaction->order_pk) }}">{{ $transaction->reference }}</a>
                                @else
                                    <strong>{{ $transaction->reference }}</strong>
                                @endif
                                <small>{{ $transaction->counterparty }}</small>
                                @if($transaction->category === 'Gaji')
                                    <small>Periode {{ $transaction->reference }}</small>
                                @endif
                            </td>
                            <td>{{ $transaction->note ?? '—' }}</td>
                            <td class="{{ $transaction->direction === 'Masuk' ? 'revenue-amount-in' : ($transaction->direction === 'Omzet' ? 'revenue-amount-revenue' : 'revenue-amount-out') }}">
                                @if($transaction->direction === 'Masuk')
                                    +
                                @elseif($transaction->direction === 'Keluar')
                                    −
                                @endif
                                Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Belum ada transaksi pada rentang tanggal ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $transactions->links() }}
    </section>
@endsection
