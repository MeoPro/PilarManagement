@extends('layouts.admin')

@section('title', 'Omzet')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Finance · {{ $businessUnitLabel }}</p>
            <h1>Omzet dan Arus Kas</h1>
            <p>
                @if($isCombinedView)
                    Ringkasan PB dan PF dalam satu tampilan. Penjualan PF ke PB ditampilkan sebagai transaksi internal terpisah dan tidak dihitung dua kali pada total gabungan.
                @elseif($isFactoryUnit)
                    Nilai order PF dipisahkan dari omzet aktual; omzet aktual dihitung sesuai jumlah yang diterima PB.
                @else
                    Pemasukan dicatat menurut tanggal pembayaran diterima, bukan tanggal order dibuat.
                @endif
            </p>
        </div>
        @if(auth()->user()->isOwner())
            @if($isCombinedView)
                <a class="btn" href="{{ route('revenue.index', ['from' => $fromDate, 'to' => $toDate]) }}">Lihat unit aktif</a>
            @else
                <a class="btn" href="{{ route('revenue.index', ['from' => $fromDate, 'to' => $toDate, 'view' => 'combined']) }}">Omzet Gabungan PB + PF</a>
            @endif
        @endif
        @if(auth()->user()->canAccessMenu('expenses'))
            <a class="btn btn-primary" href="{{ route('expenses.create') }}">+ Catat Pengeluaran</a>
        @endif
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    <form action="{{ route('revenue.index') }}" method="GET" class="search-form orders-filter-form revenue-filter-form">
        @if($isCombinedView)
            <input type="hidden" name="view" value="combined">
        @endif
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
        @if($isCombinedView)
            <article class="revenue-summary-card is-monthly-orders">
                <span>Total order PB bulan ini</span>
                <strong>Rp {{ number_format($monthlyOrderTotal, 0, ',', '.') }}</strong>
                <small>Nilai order dibuat bulan ini, tidak termasuk order batal</small>
            </article>
            <article class="revenue-summary-card is-monthly-orders">
                <span>Nilai order PF ke PB</span>
                <strong>Rp {{ number_format($factoryOrderValueTotal, 0, ',', '.') }}</strong>
                <small>Proyeksi internal, bukan omzet gabungan aktual</small>
            </article>
            <article class="revenue-summary-card is-incoming">
                <span>Penjualan internal PF ke PB</span>
                <strong>Rp {{ number_format($factorySaleTotal, 0, ',', '.') }}</strong>
                <small>Realisasi PF ditampilkan terpisah dan dieliminasi dari omzet gabungan</small>
            </article>
        @elseif($isFactoryUnit)
            <article class="revenue-summary-card is-monthly-orders">
                <span>Nilai order PF ke PB</span>
                <strong>Rp {{ number_format($factoryOrderValueTotal, 0, ',', '.') }}</strong>
                <small>Harga PF per unit × jumlah order; nilai ini belum dihitung sebagai omzet aktual</small>
            </article>
            <article class="revenue-summary-card is-incoming">
                <span>Omzet terealisasi PF ke PB</span>
                <strong>Rp {{ number_format($factorySaleTotal, 0, ',', '.') }}</strong>
                <small>Harga PF per unit × jumlah produk yang diterima PB</small>
            </article>
        @else
            <article class="revenue-summary-card is-monthly-orders">
                <span>Total order bulan ini</span>
                <strong>Rp {{ number_format($monthlyOrderTotal, 0, ',', '.') }}</strong>
                <small>Nilai order dibuat bulan ini, tidak termasuk order batal</small>
            </article>
        @endif
        <article class="revenue-summary-card is-incoming">
            <span>{{ $isCombinedView ? 'Uang masuk eksternal gabungan' : 'Total uang masuk' }}</span>
            <strong>Rp {{ number_format($totalIncoming, 0, ',', '.') }}</strong>
            <small>
                @if($isCombinedView)
                    Pembayaran pelanggan PB dan pengembalian cashbond; penjualan PF ke PB tidak dihitung ulang
                @else
                    Termasuk pengembalian cashbond Rp {{ number_format($cashbondRepaymentTotal, 0, ',', '.') }}
                @endif
            </small>
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
            <span>{{ $isCombinedView ? 'Arus kas gabungan' : 'Arus bersih' }}</span>
            <strong>Rp {{ number_format($netCashFlow, 0, ',', '.') }}</strong>
            <small>{{ $isCombinedView ? 'Uang masuk eksternal gabungan dikurangi seluruh uang keluar PB dan PF' : 'Uang masuk dikurangi uang keluar pada rentang ini' }}</small>
        </article>
    </section>

    @if($isCombinedView)
        <section class="revenue-outgoing-breakdown" aria-label="Ringkasan per unit">
            <strong>Ringkasan per unit</strong>
            @foreach($unitSummaries as $unitSummary)
                <span>
                    {{ $unitSummary['label'] }} · Masuk Rp {{ number_format($unitSummary['incoming'], 0, ',', '.') }}
                    · Keluar Rp {{ number_format($unitSummary['outgoing'], 0, ',', '.') }}
                    · Bersih Rp {{ number_format($unitSummary['net'], 0, ',', '.') }}
                </span>
            @endforeach
            <small>Uang masuk PF mencakup penjualan internal ke PB dan tidak ditambahkan lagi ke total gabungan.</small>
        </section>
    @endif

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
                <p>
                    @if($isCombinedView)
                        Pembayaran eksternal, penjualan internal PF ke PB, nilai order, dan pengeluaran sesuai tanggal transaksi.
                    @else
                        Pembayaran, nilai order PF, omzet terealisasi, dan pengeluaran sesuai tanggal transaksi.
                    @endif
                </p>
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
                                <span class="revenue-direction-badge {{ $transaction->direction === 'Masuk' ? 'is-incoming' : ($transaction->direction === 'Internal' ? 'is-internal' : (in_array($transaction->direction, ['Omzet', 'Order'], true) ? 'is-revenue' : 'is-outgoing')) }}">
                                    {{ $transaction->direction }} · {{ $transaction->category }}
                                </span>
                            </td>
                            <td>
                                @if((int) $transaction->order_pk > 0 && auth()->user()->canAccessMenu('orders'))
                                    <a href="{{ route('orders.show', $transaction->order_pk) }}">{{ $transaction->reference }}</a>
                                @elseif((int) $transaction->material_pk > 0 && auth()->user()->canAccessMenu('materials'))
                                    <a href="{{ route('materials.show', $transaction->material_pk) }}">{{ $transaction->reference }}</a>
                                @elseif((int) $transaction->expense_pk > 0 && auth()->user()->canAccessMenu('expenses'))
                                    <a href="{{ route('expenses.show', $transaction->expense_pk) }}">{{ $transaction->reference }}</a>
                                @else
                                    <strong>{{ $transaction->reference }}</strong>
                                @endif
                                <small>{{ $transaction->counterparty }}</small>
                                @if($transaction->category === 'Gaji')
                                    <small>Periode {{ $transaction->reference }}</small>
                                @endif
                                @if($transaction->direction === 'Order')
                                    <small>Nilai order, belum dihitung sebagai omzet aktual</small>
                                @endif
                            </td>
                            <td>{{ $transaction->note ?? '—' }}</td>
                            <td class="{{ $transaction->direction === 'Masuk' ? 'revenue-amount-in' : ($transaction->direction === 'Internal' ? 'revenue-amount-internal' : (in_array($transaction->direction, ['Omzet', 'Order'], true) ? 'revenue-amount-revenue' : 'revenue-amount-out')) }}">
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
