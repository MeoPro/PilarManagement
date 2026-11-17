@extends('layouts.admin')

@section('title', ($isEditing ? 'Ubah' : 'Tambah').' Karyawan')

@section('content')
    @php($employeeType = old('employee_type', $employee->employee_type ?? 'Harian'))
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>{{ $isEditing ? 'Ubah' : 'Tambah' }} Karyawan</h1>
            <p>Lengkapi informasi karyawan dan pengaturan kehadirannya.</p>
        </div>
    </div>

    <div class="card employee-form-card">
    <div
        id="employee-form-errors"
        class="field-error"
        role="alert"
        aria-live="assertive"
        @if(!$errors->any()) hidden @endif
    >
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>

    <form
        action="{{ $isEditing ? route('admin.employees.update', $employee) : route('admin.employees.store') }}"
        method="POST"
        enctype="multipart/form-data"
        data-ajax-validation
        data-validation-errors="employee-form-errors"
    >
        @csrf
        @if($isEditing)
            @method('PUT')
        @endif

        <label for="full_name">Nama lengkap</label>
        <input id="full_name" name="full_name" value="{{ old('full_name', $employee->full_name) }}" required maxlength="255">

        <label for="photo">Foto (opsional)</label>
        <input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
        @if($isEditing && $employee->photo_path)
            <img src="{{ route('admin.employees.photo', $employee) }}" alt="Foto {{ $employee->full_name }}">
        @endif

        <label for="address">Alamat</label>
        <textarea id="address" name="address" rows="3" required>{{ old('address', $employee->address) }}</textarea>

        <label for="phone">Nomor telepon</label>
        <input id="phone" type="tel" name="phone" value="{{ old('phone', $employee->phone) }}" maxlength="50">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email', $employee->email) }}" maxlength="255">

        @if($canSelectUnit)
            <label for="business_unit">Unit</label>
            <select id="business_unit" name="business_unit" required>
                @foreach(['bali' => 'Pilar Bali', 'factory' => 'Pilar Factory'] as $unit => $label)
                    @if(auth()->user()->canAccessUnit($unit))
                        <option value="{{ $unit }}" @selected(old('business_unit', $employee->business_unit) === $unit)>{{ $label }}</option>
                    @endif
                @endforeach
            </select>
        @endif

        <label for="employee_type">Jenis kerja</label>
        <select id="employee_type" name="employee_type" required data-employee-type-select>
            @foreach(['Harian', 'Borongan'] as $workType)
                <option value="{{ $workType }}" @selected($employeeType === $workType)>{{ $workType }}</option>
            @endforeach
        </select>
        @error('employee_type')
            <span class="form-error">{{ $message }}</span>
        @enderror

        <label for="cashbond">Cashbond (sisa hutang, Rp)</label>
        <input
            id="cashbond"
            type="text"
            inputmode="decimal"
            name="cashbond"
            data-money-input
            value="{{ old('cashbond', $employee->cashbond !== null ? number_format((float) $employee->cashbond, 0, '.', ',') : '0') }}"
            autocomplete="off"
        >
        <small>Isi saldo cashbond yang masih belum dibayar.</small>

        <label for="daily_wage">Gaji harian (Rp)</label>
        <input
            id="daily_wage"
            type="text"
            inputmode="decimal"
            name="daily_wage"
            data-money-input
            data-daily-wage-field
            value="{{ old('daily_wage', $employee->daily_wage !== null ? number_format((float) $employee->daily_wage, 0, '.', ',') : '') }}"
            @required($employeeType === 'Harian')
            @disabled($employeeType !== 'Harian')
            autocomplete="off"
        >
        <small>Hanya untuk karyawan berjenis kerja Harian. Contoh: 100,000.</small>

        <label for="started_at">Awal mulai kerja</label>
        <input id="started_at" type="date" name="started_at" value="{{ old('started_at', $employee->started_at?->format('Y-m-d') ?? $localToday) }}" max="{{ $localToday }}" required>

        <label for="status">Status karyawan</label>
        <select id="status" name="status" required>
            @foreach(['Aktif', 'Nonaktif'] as $employeeStatus)
                <option value="{{ $employeeStatus }}" @selected(old('status', $employee->status ?? 'Aktif') === $employeeStatus)>
                    {{ $employeeStatus }}
                </option>
            @endforeach
        </select>

        <div class="employee-form-actions">
            <button class="btn-primary" type="submit">{{ $isEditing ? 'Simpan Perubahan' : 'Simpan Karyawan' }}</button>
            <a class="btn" href="{{ route('admin.employees.index') }}">Kembali</a>
        </div>
    </form>
    </div>

@endsection
