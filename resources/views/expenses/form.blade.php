@extends('layouts.admin')

@section('title', $isEditing ? 'Edit Pengeluaran' : 'Catat Pengeluaran')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional / Pengeluaran</p>
            <h1>{{ $isEditing ? 'Edit Pengeluaran' : 'Catat Pengeluaran' }}</h1>
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
        action="{{ $isEditing ? route('expenses.update', $expense) : route('expenses.store') }}"
        method="POST"
    >
        @csrf
        @if($isEditing)
            @method('PUT')
        @endif

        @if(!$isEditing)
            <div>
                <label>Unit</label>
                <p>{{ $unitLabels[$selectedUnit] ?? $selectedUnit }}</p>
            </div>
        @elseif($isEditing)
            <div>
                <label>Unit</label>
                <p>{{ $unitLabels[$expense->business_unit] ?? $expense->business_unit }}</p>
            </div>
        @endif

        <div>
            <label for="expense-description">Deskripsi</label>
            <input id="expense-description" type="text" name="description" value="{{ old('description', $expense->description) }}" maxlength="255" required>
        </div>

        <div>
            <label for="expense-quantity">Jumlah</label>
            <input id="expense-quantity" type="number" name="quantity" min="0.001" step="0.001" value="{{ old('quantity', $expense->quantity) }}" required>
        </div>

        <div>
            <label for="expense-unit">Satuan</label>
            <input id="expense-unit" type="text" name="unit" value="{{ old('unit', $expense->unit) }}" maxlength="50" placeholder="Contoh: liter, kali, unit">
        </div>

        <div>
            <label for="expense-unit-price">Harga Satuan (Rp)</label>
            <input
                id="expense-unit-price"
                type="text"
                name="unit_price"
                inputmode="decimal"
                data-money-input
                oninput="formatExpenseMoney(this)"
                value="{{ old('unit_price', $isEditing ? number_format((float) $expense->unit_price, 2, '.', ',') : '') }}"
                required
            >
        </div>

        <div>
            <label for="expense-date">Tanggal</label>
            <input id="expense-date" type="date" name="occurred_at" value="{{ old('occurred_at', $expense->occurred_at?->format('Y-m-d') ?? now(auth()->user()->timezone())->toDateString()) }}" required>
        </div>

        <div>
            <label for="expense-note">Catatan</label>
            <textarea id="expense-note" name="note" rows="3" maxlength="2000">{{ old('note', $expense->note) }}</textarea>
        </div>

        @if($isEditing)
            <p>Total saat ini: <strong>Rp {{ number_format((float) $expense->total, 2, ',', '.') }}</strong></p>
        @endif

        <div class="employee-form-actions">
            <button class="btn-primary" type="submit">{{ $isEditing ? 'Simpan Perubahan' : 'Simpan Pengeluaran' }}</button>
            <a class="btn" href="{{ $isEditing ? route('expenses.show', $expense) : route('expenses.index') }}">Batal</a>
        </div>
    </form>
@endsection

<script>
    function formatExpenseMoney(input)
    {
        const parts = input.value.replace(/,/g, '').replace(/[^\d.]/g, '').split('.');
        const integerPart = (parts.shift() ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const decimalPart = parts.join('').slice(0, 2);

        input.value = parts.length > 0 ? `${integerPart}.${decimalPart}` : integerPart;
    }
</script>
