@extends('layouts.admin')

@section('title', $isEditing ? 'Edit Material' : 'Tambah Material')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional / Material</p>
            <h1>{{ $isEditing ? 'Edit Material' : 'Tambah Material' }}</h1>
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
        action="{{ $isEditing ? route('materials.update', $material) : route('materials.store') }}"
        method="POST"
    >
        @csrf
        @if($isEditing)
            @method('PUT')
        @endif

        @if(!$isEditing && auth()->user()->isOwner())
            <div>
                <label for="material-business-unit">Unit</label>
                <select id="material-business-unit" name="business_unit" required>
                    <option value="">Pilih unit</option>
                    @foreach($unitLabels as $unit => $label)
                        <option value="{{ $unit }}" @selected(old('business_unit') === $unit)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @elseif($isEditing)
            <div>
                <label>Unit</label>
                <p>{{ $unitLabels[$material->business_unit] ?? $material->business_unit }}</p>
            </div>
        @endif

        <div>
            <label for="material-name">Nama Material</label>
            <input id="material-name" type="text" name="name" value="{{ old('name', $material->name) }}" maxlength="255" required>
        </div>

        <div>
            <label for="material-unit">Satuan</label>
            <input id="material-unit" type="text" name="unit" value="{{ old('unit', $material->unit) }}" maxlength="50" placeholder="Contoh: lembar, kg, meter">
        </div>

        @if($isEditing)
            <div>
                <label>Harga Satuan Pembelian Terakhir</label>
                <p>Rp {{ number_format((float) $material->unit_price, 2, ',', '.') }}</p>
                <small>Harga diperbarui melalui transaksi stok masuk agar tercatat di laporan pengeluaran.</small>
            </div>
        @else
            <div>
                <label for="material-unit-price">Harga Satuan Pembelian (Rp)</label>
                <input
                    id="material-unit-price"
                    type="text"
                    name="unit_price"
                    inputmode="decimal"
                    data-money-input
                    oninput="formatMaterialMoney(this)"
                    value="{{ old('unit_price') }}"
                    required
                >
            </div>
        @endif

        @unless($isEditing)
            <div>
                <label for="material-initial-stock">Jumlah Stok Awal</label>
                <input id="material-initial-stock" type="number" name="initial_stock" min="0.001" step="0.001" value="{{ old('initial_stock') }}" required>
                <small>Stok awal dan nilainya dicatat sebagai pembelian material.</small>
            </div>
        @endunless

        <div class="employee-form-actions">
            <button class="btn-primary" type="submit">{{ $isEditing ? 'Simpan Perubahan' : 'Simpan Material' }}</button>
            <a class="btn" href="{{ $isEditing ? route('materials.show', $material) : route('materials.index') }}">Batal</a>
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
