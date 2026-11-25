@extends('layouts.admin')

@section('title', 'Edit Transaksi Material')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional / Material</p>
            <h1>Edit Transaksi Stok</h1>
            <p>{{ $material->name }} · {{ $transaction->type === 'in' ? 'Stok Masuk' : 'Stok Keluar' }}</p>
        </div>
    </div>

    @if($errors->any())
        <div class="field-error" role="alert">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form
        class="card compact-form"
        action="{{ route('materials.transactions.update', [$material, $transaction]) }}"
        method="POST"
    >
        @csrf
        @method('PATCH')

        <div>
            <label>Jenis Transaksi</label>
            <p>{{ $transaction->type === 'in' ? 'Stok Masuk' : 'Stok Keluar' }}</p>
        </div>
        <div>
            <label for="transaction-quantity">Jumlah</label>
            <input
                id="transaction-quantity"
                type="number"
                name="quantity"
                min="0.001"
                step="0.001"
                value="{{ old('quantity', $transaction->quantity) }}"
                required
            >
        </div>

        @if($transaction->type === 'in')
            <div>
                <label for="transaction-price">Harga Pembelian per Satuan (Rp)</label>
                <input
                    id="transaction-price"
                    type="text"
                    name="unit_price"
                    inputmode="decimal"
                    data-money-input
                    oninput="formatMaterialMoney(this)"
                    value="{{ old('unit_price', number_format((float) $transaction->unit_price, 2, '.', ',')) }}"
                    required
                >
                <small>Perubahan harga atau jumlah akan langsung memperbarui nilai pembelian di Omzet.</small>
            </div>
        @else
            <div>
                <label>Harga Satuan Biaya</label>
                <p>Rp {{ number_format((float) $transaction->unit_price, 2, ',', '.') }}</p>
                <small>Harga stok keluar dihitung ulang otomatis berdasarkan rata-rata tertimbang.</small>
            </div>
        @endif

        <div>
            <label>Tanggal Transaksi</label>
            <p>{{ $transaction->occurred_at->copy()->setTimezone($timezone)->format('d/m/Y H:i') }} {{ $timezoneLabel }}</p>
        </div>
        <div>
            <label>Catatan</label>
            <p>{{ $transaction->note ?? '—' }}</p>
        </div>
        <div class="employee-form-actions">
            <button class="btn-primary" type="submit">Simpan Perubahan</button>
            <a class="btn" href="{{ route('materials.show', $material) }}">Batal</a>
        </div>
    </form>
@endsection

<script>
    function formatMaterialMoney(input)
    {
        const parts = input.value.replace(/,/g, '').replace(/[^\d.]/g, '').split('.');
        const integerPart = (parts.shift() ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const decimalPart = parts.join('').slice(0, 2);

        input.value = parts.length > 0 ? `${integerPart}.${decimalPart}` : integerPart;
    }
</script>
