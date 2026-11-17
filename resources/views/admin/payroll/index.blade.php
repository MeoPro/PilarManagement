@extends('layouts.admin')

@section('title', 'Penggajian')

@section('content')
    @php($heading = $salaryType !== '' ? 'Penggajian '.$salaryType : 'Penggajian Karyawan')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>{{ $heading }}</h1>
            <p>Gaji harian, lembur, dan borongan ditampilkan terpisah; pembayaran Harian mencakup gaji harian dan lembur.</p>
        </div>
        <a class="btn" href="{{ route('admin.attendance.index', $salaryType ? ['work_type' => $salaryType] : []) }}">Buka Absensi {{ $salaryType }}</a>
    </div>

    <nav class="attendance-type-tabs" aria-label="Jenis penggajian">
        <a class="btn {{ $salaryType === 'Harian' ? 'primary' : '' }}" href="{{ route('admin.payroll.index', ['period' => $period, 'business_unit' => $unitFilter, 'salary_type' => 'Harian']) }}">Harian</a>
        <a class="btn {{ $salaryType === 'Borongan' ? 'primary' : '' }}" href="{{ route('admin.payroll.index', ['period' => $period, 'business_unit' => $unitFilter, 'salary_type' => 'Borongan']) }}">Borongan</a>
    </nav>

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

    <form action="{{ route('admin.payroll.index') }}" method="GET" class="search-form">
        <label for="payroll-period">Bulan gaji</label>
        <input id="payroll-period" type="month" name="period" value="{{ $period }}" max="{{ $localToday->format('Y-m') }}">
        @if($salaryType !== '')
            <input type="hidden" name="salary_type" value="{{ $salaryType }}">
        @endif
        @if($canSelectUnit)
            <label for="payroll-unit">Unit</label>
            <select id="payroll-unit" name="business_unit">
                <option value="">Semua Unit</option>
                @foreach($businessUnits as $unit => $label)
                    <option value="{{ $unit }}" @selected($unitFilter === $unit)>{{ $label }}</option>
                @endforeach
            </select>
        @endif
        <button class="btn" type="submit">Tampilkan</button>
    </form>

    <section class="order-summary-grid payroll-summary" aria-label="Ringkasan finance bulan ini">
        <article>
            <span>Gaji belum dibayar periode {{ $periodStart->format('m/Y') }}</span>
            <strong>Rp {{ number_format($unpaidSalaryTotal, 0, ',', '.') }}</strong>
        </article>
        <article>
            <span>Total cashbond karyawan</span>
            <strong>Rp {{ number_format($cashbondTotal, 0, ',', '.') }}</strong>
        </article>
        <article>
            <span>Karyawan masih memiliki hutang</span>
            <strong>{{ $cashbondEmployeeCount }} orang</strong>
        </article>
        <article>
            <span>Karyawan aktif</span>
            <strong>{{ $activeEmployeeCount }} orang</strong>
        </article>
    </section>

    <p class="muted">Periode {{ $periodStart->format('d/m/Y') }}–{{ $periodEnd->format('d/m/Y') }}. Cashbond otomatis dipotong dari gaji yang dibayarkan sampai maksimal seluruh jumlah gaji tersebut.</p>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Karyawan</th>
                    @if($canSelectUnit)
                        <th>Unit</th>
                    @endif
                    @if($salaryType === '' || $salaryType === 'Harian')
                        <th>Sesi Harian</th>
                        <th>Gaji Harian</th>
                        <th>Lembur</th>
                    @endif
                    @if($salaryType === '' || $salaryType === 'Borongan')
                        <th>Gaji Borongan</th>
                    @endif
                    <th>Sisa Gaji {{ $salaryType ?: 'Bulan Ini' }}</th>
                    <th>Sudah Dibayar</th>
                    <th>Cashbond</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $employee)
                    @php($summary = $summaries[$employee->id])
                    <tr>
                        <td>{{ $employee->full_name }}</td>
                        @if($canSelectUnit)
                            <td>{{ $businessUnits[$employee->business_unit] ?? $employee->business_unit }}</td>
                        @endif
                        @if($salaryType === '' || $salaryType === 'Harian')
                            <td>{{ $summary['daily_sessions'] }}</td>
                            <td>Rp {{ number_format($summary['daily_pay_total'], 0, ',', '.') }}</td>
                            <td>Rp {{ number_format($summary['overtime_pay_total'], 0, ',', '.') }}</td>
                        @endif
                        @if($salaryType === '' || $salaryType === 'Borongan')
                            <td>Rp {{ number_format($summary['piecework_pay_total'], 0, ',', '.') }}</td>
                        @endif
                        <td><strong>Rp {{ number_format($summary['salary_due'], 0, ',', '.') }}</strong></td>
                        <td>Rp {{ number_format($summary['salary_paid_for_type'], 0, ',', '.') }}</td>
                        <td>Rp {{ number_format((float) $employee->cashbond, 0, ',', '.') }}</td>
                        <td>
                            @if($summary['salary_due'] > 0 || (float) $employee->cashbond > 0)
                                @php($dialogId = 'payroll-payment-'.$employee->id)
                                <button class="btn" type="button" data-open-payroll-dialog="{{ $dialogId }}">
                                    {{ $summary['salary_due'] > 0 ? 'Bayar Gaji' : 'Bayar Cashbond' }}
                                </button>
                                <dialog id="{{ $dialogId }}" class="payroll-payment-dialog" aria-labelledby="{{ $dialogId }}-title">
                                    <div class="payroll-payment-dialog-header">
                                        <div>
                                            <p class="page-eyebrow">Pembayaran</p>
                                            <h2 id="{{ $dialogId }}-title">{{ $employee->full_name }}</h2>
                                        </div>
                                        <button class="payroll-payment-close" type="button" data-close-payroll-dialog aria-label="Tutup popup">×</button>
                                    </div>
                                    <form action="{{ route('admin.payroll.pay', $employee) }}" method="POST" class="payroll-payment-form">
                                        @csrf
                                        <input type="hidden" name="period" value="{{ $period }}">
                                        <input type="hidden" name="salary_type" value="{{ $salaryType }}">
                                        <input type="hidden" name="employee_id" value="{{ $employee->id }}">

                                        @if($summary['salary_due'] > 0)
                                            <p>Sisa gaji {{ $salaryType ?: 'periode ini' }}: <strong>Rp {{ number_format($summary['salary_due'], 0, ',', '.') }}</strong></p>
                                            @if((float) $employee->cashbond > 0)
                                                <p class="muted">Cashbond Rp {{ number_format((float) $employee->cashbond, 0, ',', '.') }} akan otomatis dipotong dari pembayaran gaji.</p>
                                            @endif
                                            <label for="{{ $dialogId }}-amount">Jumlah yang dibayarkan</label>
                                            <input
                                                id="{{ $dialogId }}-amount"
                                                type="text"
                                                inputmode="decimal"
                                                name="salary_payment"
                                                data-money-input
                                                value="{{ (string) old('employee_id') === (string) $employee->id ? old('salary_payment') : '' }}"
                                                placeholder="Masukkan jumlah"
                                                autocomplete="off"
                                            >
                                            <div class="payroll-payment-actions">
                                                <button class="btn primary" type="submit">Bayar jumlah ini</button>
                                                <button class="btn" type="submit" name="pay_all" value="1" formnovalidate>Bayar semua</button>
                                            </div>
                                        @else
                                            <p>Sisa hutang Cashbond: <strong>Rp {{ number_format((float) $employee->cashbond, 0, ',', '.') }}</strong></p>
                                            <label for="{{ $dialogId }}-cashbond-amount">Jumlah pembayaran Cashbond</label>
                                            <input
                                                id="{{ $dialogId }}-cashbond-amount"
                                                type="text"
                                                inputmode="decimal"
                                                name="cashbond_payment"
                                                data-money-input
                                                value="{{ (string) old('employee_id') === (string) $employee->id ? old('cashbond_payment') : '' }}"
                                                placeholder="Masukkan jumlah"
                                                autocomplete="off"
                                            >
                                            <div class="payroll-payment-actions">
                                                <button class="btn primary" type="submit">Bayar jumlah ini</button>
                                                <button class="btn" type="submit" name="pay_cashbond_all" value="1">Bayar semua hutang</button>
                                            </div>
                                        @endif
                                    </form>
                                </dialog>
                            @else
                                <span class="muted">Tidak ada pembayaran</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $canSelectUnit ? ($salaryType === '' ? 10 : ($salaryType === 'Harian' ? 8 : 7)) : ($salaryType === '' ? 9 : ($salaryType === 'Harian' ? 7 : 6)) }}">Belum ada karyawan pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $employees->links() }}
@endsection
