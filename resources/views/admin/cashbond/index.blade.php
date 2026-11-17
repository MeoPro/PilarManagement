@extends('layouts.admin')

@section('title', 'Cashbond')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Finance</p>
            <h1>Cashbond Karyawan</h1>
            <p>Catat pinjaman karyawan. Batas total pinjaman adalah dua kali gaji yang sudah terkumpul sampai tanggal Cashbond.</p>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif
    @if($errors->any())
        <ul class="error" role="alert">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="card employee-form-card">
        <h2>Catat Cashbond</h2>
        <form action="{{ route('admin.cashbond.store') }}" method="POST">
            @csrf
            @if($canSelectUnit)
                <label for="cashbond-unit">Unit</label>
                <select id="cashbond-unit" name="business_unit">
                    <option value="">Semua Unit</option>
                    @foreach($businessUnits as $unit => $label)
                        <option value="{{ $unit }}" @selected(old('business_unit', $unitFilter) === $unit)>{{ $label }}</option>
                    @endforeach
                </select>
            @endif

            <label for="cashbond-employee">Karyawan</label>
            <select id="cashbond-employee" name="employee_id" required>
                <option value="">Pilih karyawan</option>
                @foreach($employees as $employee)
                    <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>
                        {{ $employee->full_name }} — hutang saat ini Rp {{ number_format((float) $employee->cashbond, 0, ',', '.') }}
                    </option>
                @endforeach
            </select>

            <label for="cashbond-date">Tanggal</label>
            <input id="cashbond-date" type="date" name="advance_date" value="{{ old('advance_date', $localToday->toDateString()) }}" max="{{ $localToday->toDateString() }}" required>

            <label for="cashbond-amount">Jumlah pinjaman</label>
            <input id="cashbond-amount" type="text" name="amount" inputmode="decimal" data-money-input value="{{ old('amount') }}" placeholder="Contoh: 500.000" autocomplete="off" required>

            <button class="btn primary" type="submit">Simpan Cashbond</button>
        </form>
    </div>

    <section class="page-section">
        <div class="section-heading">
            <div>
                <h2>Riwayat Cashbond</h2>
                <p class="muted">Saldo hutang diperbarui saat pinjaman dicatat dan berkurang otomatis dari pembayaran gaji.</p>
            </div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Karyawan</th>
                        <th>Gaji terkumpul saat pinjaman</th>
                        <th>Jumlah pinjaman</th>
                        <th>Sisa hutang setelah transaksi</th>
                        <th>Dicatat oleh</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($advances as $advance)
                        <tr>
                            <td>{{ $advance->advance_date->format('d/m/Y') }}</td>
                            <td>{{ $advance->employee->full_name }}</td>
                            <td>Rp {{ number_format((float) $advance->salary_earned, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $advance->amount, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $advance->balance_after, 0, ',', '.') }}</td>
                            <td>{{ $advance->recordedBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Belum ada transaksi Cashbond.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $advances->links() }}
    </section>
@endsection
