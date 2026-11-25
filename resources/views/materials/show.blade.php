@extends('layouts.admin')

@section('title', $material->name)

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional / Material</p>
            <h1>{{ $material->name }}</h1>
            <p>{{ $material->unit ?? 'Satuan belum ditentukan' }} · {{ $material->business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}</p>
        </div>
        <div class="order-list-header-actions">
            <a class="btn" href="{{ route('materials.index') }}">Kembali</a>
            <a class="btn btn-primary" href="{{ route('materials.edit', $material) }}">Edit</a>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    @if($errors->any())
        <div class="field-error" role="alert">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="order-summary-grid">
        <article class="order-summary-card is-completed">
            <span>Saldo Stok</span>
            <strong>{{ $material->current_stock_display }} {{ $material->unit }}</strong>
        </article>
        <article class="order-summary-card is-revenue">
            <span>Harga Rata-rata Stok</span>
            <strong>
                @if($material->average_unit_price !== null)
                    Rp {{ number_format($material->average_unit_price, 2, ',', '.') }}
                @else
                    —
                @endif
            </strong>
        </article>
    </div>

    <section class="card">
        <h2>Catat Transaksi Stok</h2>
        <form action="{{ route('materials.transactions.store', $material) }}" method="POST" class="compact-form">
            @csrf
            <div>
                <label for="transaction-type">Jenis Transaksi</label>
                <select id="transaction-type" name="type" required>
                    <option value="in" @selected(old('type', request('type')) !== 'out')>Stok Masuk</option>
                    <option value="out" @selected(old('type', request('type')) === 'out')>Stok Keluar</option>
                </select>
            </div>
            <div>
                <label for="transaction-quantity">Jumlah</label>
                <input id="transaction-quantity" type="number" name="quantity" min="0.001" step="0.001" value="{{ old('quantity') }}" required>
            </div>
            <div>
                <div data-material-purchase-price>
                    <label for="transaction-price">Harga Pembelian per Satuan (Rp)</label>
                    <input
                        id="transaction-price"
                        type="text"
                        name="unit_price"
                        inputmode="decimal"
                        data-money-input
                        oninput="formatMaterialMoney(this)"
                        value="{{ old('unit_price', number_format((float) $material->unit_price, 2, '.', ',')) }}"
                    >
                    <small>Isi harga setiap stok masuk. Stok keluar dihitung dengan biaya rata-rata tertimbang.</small>
                </div>
            </div>
            <div>
                <label for="transaction-date">Tanggal</label>
                <input id="transaction-date" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now($timezone)->format('Y-m-d\TH:i')) }}">
            </div>
            <div>
                <label for="transaction-note">Catatan</label>
                <input id="transaction-note" type="text" name="note" maxlength="2000" value="{{ old('note') }}">
            </div>
            <div class="employee-form-actions">
                <button class="btn-primary" type="submit">Simpan Transaksi</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Riwayat Stok</h2>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Transaksi</th>
                        <th>Jumlah</th>
                        <th>Harga Satuan Biaya</th>
                        <th>Catatan</th>
                        <th>Dicatat Oleh</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        <tr>
                            <td>{{ $transaction->occurred_at->copy()->setTimezone($timezone)->format('d/m/Y H:i') }} {{ $timezoneLabel }}</td>
                            <td>{{ $transaction->type === 'in' ? 'Masuk' : 'Keluar' }}</td>
                            <td>{{ $transaction->quantity_display }} {{ $material->unit }}</td>
                            <td>Rp {{ number_format((float) $transaction->unit_price, 2, ',', '.') }}</td>
                            <td>{{ $transaction->note ?? '—' }}</td>
                            <td>{{ $transaction->createdBy?->name ?? '—' }}</td>
                            <td>
                                <a class="btn" href="{{ route('materials.transactions.edit', [$material, $transaction]) }}">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Belum ada transaksi stok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $transactions->links() }}
    </section>
@endsection

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const transactionType = document.querySelector('#transaction-type');
        const purchasePrice = document.querySelector('[data-material-purchase-price]');
        const purchasePriceInput = document.querySelector('#transaction-price');

        if (!transactionType || !purchasePrice || !purchasePriceInput) {
            return;
        }

        const updatePurchasePriceVisibility = function () {
            const isPurchase = transactionType.value === 'in';
            purchasePrice.hidden = !isPurchase;
            purchasePriceInput.required = isPurchase;
        };

        transactionType.addEventListener('change', updatePurchasePriceVisibility);
        updatePurchasePriceVisibility();
    });

    function formatMaterialMoney(input)
    {
        const parts = input.value.replace(/,/g, '').replace(/[^\d.]/g, '').split('.');
        const integerPart = (parts.shift() ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const decimalPart = parts.join('').slice(0, 2);

        input.value = parts.length > 0 ? `${integerPart}.${decimalPart}` : integerPart;
    }
</script>
