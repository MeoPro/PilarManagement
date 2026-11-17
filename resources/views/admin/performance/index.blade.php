@extends('layouts.admin')

@section('title', 'Peforma Karyawan')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>Peforma Karyawan</h1>
            <p>Ringkasan performa bulan terpilih. Performa tahunan dan porsi Alpa/Izin tersedia di detail tiap karyawan.</p>
        </div>
        <div class="order-list-header-actions">
            @if(auth()->user()->canAccessMenu('attendance'))
                <a class="btn" href="{{ route('admin.attendance.index') }}">Catat Absensi</a>
            @endif
        </div>
    </div>

    @if($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <form action="{{ route('admin.performance.index') }}" method="GET" class="search-form performance-filter-form">
        <label>
            <span>Bulan</span>
            <input type="month" name="month" value="{{ $month }}" max="{{ now(auth()->user()->timezone())->format('Y-m') }}" onchange="this.form.submit()">
        </label>
        @if($canSelectUnit)
            <label>
                <span>Unit</span>
                <select name="business_unit" onchange="this.form.submit()">
                    <option value="">Semua Unit</option>
                    @foreach($businessUnits as $unit => $label)
                        <option value="{{ $unit }}" @selected($unitFilter === $unit)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label>
            <span>Jenis Karyawan</span>
            <select name="employee_type" onchange="this.form.submit()">
                <option value="">Semua Jenis</option>
                @foreach($employeeTypes as $type)
                    <option value="{{ $type }}" @selected($employeeTypeFilter === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn">Tampilkan</button>
    </form>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    @if(auth()->user()->isOwner())
        <section class="performance-admin-section">
            <div class="section-heading">
                <div>
                    <h2>Ringkasan Aktivitas Admin · {{ $monthStart->format('m/Y') }}</h2>
                    <p class="muted">Pilih profil admin untuk melihat bukti foto dan kehadiran per hari.</p>
                </div>
            </div>
            <div class="performance-admin-grid">
                @forelse($adminActivity['admins'] as $admin)
                    <a
                        class="performance-admin-card {{ (string) $selectedAdminId === (string) $admin->id ? 'is-selected' : '' }}"
                        href="{{ route('admin.performance.index', ['month' => $month, 'admin_id' => $admin->id]) }}"
                        @if((string) $selectedAdminId === (string) $admin->id) aria-current="page" @endif
                    >
                        <div class="performance-admin-card-heading">
                            <strong>{{ $admin->name }}</strong>
                            <span>{{ $admin->business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}</span>
                        </div>
                        <div class="performance-admin-card-metrics">
                            <span>{{ number_format($admin->monthly_attendance_percentage, 1, ',', '.') }}% hadir</span>
                            <span>Foto {{ $admin->monthly_photo_count }}/{{ $admin->monthly_photo_target }}</span>
                        </div>
                    </a>
                @empty
                    <p class="muted">Belum ada admin aktif pada periode ini.</p>
                @endforelse
            </div>
        </section>

        @if($selectedAdminId !== null)
            @php($selectedAdmin = $adminActivity['admins']->firstWhere('id', $selectedAdminId))
            <section class="card performance-admin-detail">
                <h3>Aktivitas harian admin: {{ $selectedAdmin?->name }}</h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Sesi pagi</th>
                                <th>Sesi siang</th>
                                <th>Kehadiran</th>
                                <th>Foto terkirim</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($adminActivity['dailyActivities'] as $activity)
                                @php($dayCredit = $activity['attendance_credit'])
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::parse($activity['attendance_date'])->format('d/m/Y') }}</td>
                                    @foreach(['morning' => ['Pagi', 'morning_photo_one_path', 'morning_photo_two_path'], 'afternoon' => ['Siang', 'afternoon_photo_one_path', 'afternoon_photo_two_path']] as $session => [$label, $photoOneKey, $photoTwoKey])
                                        @php($sessionComplete = $activity[$session === 'morning' ? 'morning_complete' : 'afternoon_complete'])
                                        <td>
                                            <span class="kanban-session-status {{ $sessionComplete ? 'is-complete' : 'is-incomplete' }}">
                                                {{ $sessionComplete ? 'Lengkap · 2/2' : 'Belum lengkap' }}
                                            </span>
                                            @foreach([$activity[$photoOneKey], $activity[$photoTwoKey]] as $photoIndex => $photoPath)
                                                @if($photoPath && $activity['attendance_id'])
                                                    <a href="{{ route('kanban.attendance.session-photos.show', [$activity['attendance_id'], $session, $photoIndex + 1]) }}" target="_blank" rel="noopener">Foto {{ $photoIndex + 1 }}</a>
                                                @endif
                                            @endforeach
                                        </td>
                                    @endforeach
                                    <td>
                                        <span class="kanban-day-credit {{ $dayCredit >= 1 ? 'is-full' : ($dayCredit > 0 ? 'is-half' : 'is-empty') }}">
                                            {{ number_format($dayCredit, 1, ',', '.') }} hari
                                        </span>
                                    </td>
                                    <td>{{ $activity['uploaded_photo_count'] }}/4</td>
                                </tr>
                            @empty
                                <tr><td colspan="5">Tidak ada aktivitas pada hari kerja di periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    <section class="performance-dashboard">
        <div class="section-heading">
            <div>
                <h2>Dashboard Performa Karyawan</h2>
                <p class="muted">
                    Rata-rata untuk {{ $employeeTypeFilter !== '' ? $employeeTypeFilter : 'semua jenis karyawan' }}
                    · {{ $businessUnits[$unitFilter] ?? 'Semua unit' }}
                </p>
            </div>
        </div>
        <div class="performance-summary-grid">
            @if($unitFilter === \App\Models\User::UNIT_BALI && $employeeTypeFilter === 'Borongan')
                <article class="performance-summary-card">
                    <span>Rata-rata produk selesai</span>
                    <div>
                        <strong>{{ number_format($dashboardSummary['month']['piecework_completion_percentage'], 1, ',', '.') }}%</strong>
                        <small>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('F') }}</small>
                    </div>
                </article>
                <article class="performance-summary-card">
                    <span>Rata-rata ketepatan waktu</span>
                    <div>
                        <strong>{{ $dashboardSummary['month']['piecework_on_time_percentage'] !== null ? number_format($dashboardSummary['month']['piecework_on_time_percentage'], 1, ',', '.') : '—' }}{{ $dashboardSummary['month']['piecework_on_time_percentage'] !== null ? '%' : '' }}</strong>
                        <small>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('F') }}</small>
                    </div>
                </article>
            @else
                <article class="performance-summary-card">
                    <span>Rata-rata kehadiran</span>
                    <div>
                        <strong>{{ number_format($dashboardSummary['month']['attendance_percentage'], 1, ',', '.') }}%</strong>
                        <small>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('F') }}</small>
                    </div>
                </article>
            @endif
        </div>
    </section>

    <div class="table-wrapper">
        <table class="performance-table">
            <thead>
                <tr>
                    <th>Karyawan</th>
                    <th>Performa Bulan Ini</th>
                    <th>Izin</th>
                    <th>Alpa</th>
                    <th>Detail Performa</th>
                    @if(auth()->user()->canAccessMenu('attendance'))
                        <th>Koreksi</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $employee)
                    @php($monthSummary = $summaries[$employee->id]['month'])
                    @php($yearSummary = $summaries[$employee->id]['year'])
                    @php($pieceworkSummary = $summaries[$employee->id]['piecework'])
                    @php($monthPieceworkPerformance = $summaries[$employee->id]['piecework_performance']['month'])
                    @php($yearPieceworkPerformance = $summaries[$employee->id]['piecework_performance']['year'])
                    @php($isFactoryPiecework = $employee->business_unit === \App\Models\User::UNIT_FACTORY && $employee->employee_type === 'Borongan')
                    @php($isBaliPiecework = $employee->business_unit === \App\Models\User::UNIT_BALI && $employeeTypeFilter === 'Borongan')
                    @php($canRecordBaliBoronganAttendance = $employee->canRecordBaliBoronganAttendance())
                    @php($pieceworkPercentage = $pieceworkSummary['target_quantity'] > 0 ? min($pieceworkSummary['produced_quantity'], $pieceworkSummary['target_quantity']) / $pieceworkSummary['target_quantity'] * 100 : 0)
                    <tr>
                        <td>
                            <strong>{{ $employee->full_name }}</strong>
                            <small>{{ $businessUnits[$employee->business_unit] ?? $employee->business_unit }} · {{ $employee->employee_type }}</small>
                        </td>
                        <td>
                            @if($isFactoryPiecework)
                                <strong>Hasil borongan: Rp {{ number_format($monthSummary['piecework_wage'], 0, ',', '.') }}</strong>
                                <small>{{ number_format($monthSummary['attendance_percentage'], 1, ',', '.') }}% hadir · {{ $monthSummary['present'] }} sesi</small>
                                <small>{{ number_format($pieceworkPercentage, 1, ',', '.') }}% target jumlah · {{ $pieceworkSummary['produced_quantity'] }} / {{ $pieceworkSummary['target_quantity'] }} unit</small>
                            @elseif($isBaliPiecework)
                                <strong>{{ number_format($monthPieceworkPerformance['completion_percentage'], 1, ',', '.') }}% produk selesai</strong>
                                <small>{{ $monthPieceworkPerformance['produced_quantity'] }} / {{ $monthPieceworkPerformance['target_quantity'] }} produk</small>
                                <strong>
                                    @if($monthPieceworkPerformance['on_time_percentage'] !== null)
                                        {{ number_format($monthPieceworkPerformance['on_time_percentage'], 1, ',', '.') }}% tepat waktu
                                    @else
                                        Belum ada tugas jatuh tempo
                                    @endif
                                </strong>
                                <small>{{ $monthPieceworkPerformance['on_time_assignments'] }} / {{ $monthPieceworkPerformance['due_assignments'] }} tugas tepat waktu</small>
                            @else
                                <strong>{{ number_format($monthSummary['attendance_percentage'], 1, ',', '.') }}% hadir</strong>
                                <small>{{ $monthSummary['present'] }} sesi hadir · {{ number_format($monthSummary['absence_percentage'], 1, ',', '.') }}% tidak hadir</small>
                            @endif
                        </td>
                        <td>
                            @if($isBaliPiecework && ! $canRecordBaliBoronganAttendance)
                                —
                            @else
                                <strong>{{ $monthSummary['izin'] }}</strong>
                            @endif
                        </td>
                        <td>
                            @if($isBaliPiecework && ! $canRecordBaliBoronganAttendance)
                                —
                            @else
                                <strong>{{ $monthSummary['alpa'] }}</strong>
                            @endif
                        </td>
                        <td>
                            <details class="performance-employee-detail">
                                <summary class="btn btn-secondary">Detail performa</summary>
                                <div class="performance-employee-detail-content">
                                    <section class="performance-employee-detail-section">
                                        <h4>Performa Tahun {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->year }}</h4>
                                        @if($isFactoryPiecework)
                                            <strong>Hasil borongan: Rp {{ number_format($yearSummary['piecework_wage'], 0, ',', '.') }}</strong>
                                            <small>{{ number_format($yearSummary['attendance_percentage'], 1, ',', '.') }}% hadir · {{ $yearSummary['present'] }} sesi</small>
                                        @elseif($isBaliPiecework)
                                            <strong>{{ number_format($yearPieceworkPerformance['completion_percentage'], 1, ',', '.') }}% produk selesai</strong>
                                            <small>{{ $yearPieceworkPerformance['produced_quantity'] }} / {{ $yearPieceworkPerformance['target_quantity'] }} produk</small>
                                            <strong>
                                                @if($yearPieceworkPerformance['on_time_percentage'] !== null)
                                                    {{ number_format($yearPieceworkPerformance['on_time_percentage'], 1, ',', '.') }}% tepat waktu
                                                @else
                                                    Belum ada tugas jatuh tempo
                                                @endif
                                            </strong>
                                            <small>{{ $yearPieceworkPerformance['on_time_assignments'] }} / {{ $yearPieceworkPerformance['due_assignments'] }} tugas tepat waktu</small>
                                        @else
                                            <strong>{{ number_format($yearSummary['attendance_percentage'], 1, ',', '.') }}% hadir</strong>
                                            <small>{{ $yearSummary['present'] }} sesi hadir</small>
                                        @endif
                                    </section>
                                    @if(! $isBaliPiecework || $canRecordBaliBoronganAttendance)
                                        <section class="performance-employee-detail-section">
                                            <h4>Rincian Alpa dan Izin</h4>
                                            <p>{{ $monthStart->translatedFormat('F Y') }}: Izin {{ $monthSummary['izin'] }} · Alpa {{ $monthSummary['alpa'] }}</p>
                                            <p>
                                                Porsi {{ $monthStart->translatedFormat('F') }}:
                                                Alpa {{ number_format($monthSummary['alpa_share'], 1, ',', '.') }}%
                                                · Izin {{ number_format($monthSummary['izin_share'], 1, ',', '.') }}%
                                            </p>
                                            <p>Tahun {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->year }}: Izin {{ $yearSummary['izin'] }} · Alpa {{ $yearSummary['alpa'] }}</p>
                                            <p>
                                                Porsi tahun ini:
                                                Alpa {{ number_format($yearSummary['alpa_share'], 1, ',', '.') }}%
                                                · Izin {{ number_format($yearSummary['izin_share'], 1, ',', '.') }}%
                                            </p>
                                        </section>
                                    @endif
                                </div>
                            </details>
                        </td>
                        @if(auth()->user()->canAccessMenu('attendance'))
                            <td>
                                <a class="btn" href="{{ route('admin.employees.attendance-history', $employee) }}">Riwayat &amp; koreksi</a>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ auth()->user()->canAccessMenu('attendance') ? 6 : 5 }}">Tidak ada karyawan pada periode dan filter ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="muted performance-legend">
        Sesi tanpa status absensi dihitung sebagai Alpa. Hari Minggu tidak dihitung.
        Performa tahunan dan porsi Alpa/Izin tersedia di detail tiap karyawan; Borongan Pilar Bali menampilkan progres produk dan ketepatan waktu.
    </p>

    {{ $employees->links() }}
@endsection
