@extends('layouts.admin')

@section('title', 'Karyawan')

@section('content')
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Operasional</p>
        <h1>Karyawan</h1>
        <p>Kelola data dan status karyawan.</p>
    </div>
    <div class="order-list-header-actions">
        @if(auth()->user()->canAccessMenu('attendance'))
            <a class="btn" href="{{ route('admin.attendance.index') }}">Buka Absensi</a>
        @endif
        <a class="btn btn-primary" href="{{ route('admin.employees.create') }}">+ Tambah Karyawan</a>
    </div>
</div>

<nav class="attendance-type-tabs" aria-label="Jenis karyawan">
    <a class="btn {{ $employeeTypeFilter === '' ? 'primary' : '' }}" href="{{ route('admin.employees.index', ['business_unit' => $unitFilter]) }}">Semua</a>
    <a class="btn {{ $employeeTypeFilter === 'Harian' ? 'primary' : '' }}" href="{{ route('admin.employees.index', ['business_unit' => $unitFilter, 'employee_type' => 'Harian']) }}">Harian</a>
    <a class="btn {{ $employeeTypeFilter === 'Borongan' ? 'primary' : '' }}" href="{{ route('admin.employees.index', ['business_unit' => $unitFilter, 'employee_type' => 'Borongan']) }}">Borongan</a>
</nav>

    @if(session('success'))
        <p class="message">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    @if($canSelectUnit)
        <form action="{{ route('admin.employees.index') }}" method="GET" class="search-form">
            <select name="business_unit" aria-label="Filter unit karyawan">
                <option value="">Semua Unit</option>
                @foreach($businessUnits as $unit => $label)
                    <option value="{{ $unit }}" @selected($unitFilter === $unit)>{{ $label }}</option>
                @endforeach
            </select>
            @if($employeeTypeFilter !== '')
                <input type="hidden" name="employee_type" value="{{ $employeeTypeFilter }}">
            @endif
            <button type="submit" class="btn">Filter</button>
        </form>
    @endif

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Nama</th>
                    <th>Alamat</th>
                    <th>Telepon</th>
                    <th>Email</th>
                    @if($canSelectUnit)
                        <th>Unit</th>
                    @endif
                    <th>Jenis</th>
                    <th>Gaji Harian</th>
                    <th>Mulai Kerja</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $employee)
                    <tr>
                        <td>
                            @if($employee->photo_path)
                                <img class="employee-list-avatar" src="{{ route('admin.employees.photo', $employee) }}" alt="Foto {{ $employee->full_name }}">
                            @else
                                <span class="employee-list-avatar-placeholder" role="img" aria-label="Foto {{ $employee->full_name }} belum tersedia">
                                    <x-layout.icon name="employees" />
                                </span>
                            @endif
                        </td>
                        <td>{{ $employee->full_name }}</td>
                        <td>{{ $employee->address }}</td>
                        <td>{{ $employee->phone ?? '-' }}</td>
                        <td>{{ $employee->email ?? '-' }}</td>
                        @if($canSelectUnit)
                            <td>{{ $businessUnits[$employee->business_unit] ?? $employee->business_unit }}</td>
                        @endif
                        <td>
                            {{ $employee->employee_type }}
                        </td>
                        <td>{{ $employee->daily_wage !== null ? 'Rp '.number_format($employee->daily_wage, 0, '.', ',') : '-' }}</td>
                        <td>{{ $employee->started_at->format('d/m/Y') }}</td>
                        <td>{{ $employee->status }}</td>
                        <td>
                            <div class="order-row-actions">
                                <a class="icon-button" href="{{ route('admin.employees.edit', $employee) }}" aria-label="Edit {{ $employee->full_name }}" title="Edit karyawan">
                                    <x-layout.icon name="pencil" />
                                </a>
                                @if(auth()->user()->canAccessMenu('attendance'))
                                    <a class="icon-button" href="{{ route('admin.employees.attendance-history', $employee) }}" aria-label="Lihat absensi {{ $employee->full_name }}" title="Lihat riwayat absen">
                                        <x-layout.icon name="eye" />
                                    </a>
                                @endif
                                <form action="{{ route('admin.employees.destroy', $employee) }}" method="POST" onsubmit="return confirm('Hapus data karyawan ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="icon-button delete-button" aria-label="Hapus karyawan {{ $employee->full_name }}" title="Hapus karyawan">
                                        <x-layout.icon name="trash" />
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $canSelectUnit ? 11 : 10 }}">Belum ada data karyawan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $employees->links() }}
@endsection
