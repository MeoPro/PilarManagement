@extends('layouts.admin')

@section('title', 'Absensi')

@section('content')
    @php($heading = $workType !== '' ? 'Absensi '.$workType : 'Absensi Harian & Borongan')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>{{ $heading }}</h1>
            <p>Absensi Harian tersedia untuk karyawan Harian. Absensi Borongan PB hanya tersedia bagi karyawan yang jenis utamanya dan satu-satunya Borongan.</p>
        </div>
        @if(auth()->user()->canAccessMenu('employees'))
            <a class="btn" href="{{ route('admin.employees.index') }}">Daftar Karyawan</a>
        @endif
    </div>

    <nav class="attendance-type-tabs" aria-label="Jenis absensi">
        <a class="btn {{ $workType === 'Harian' ? 'primary' : '' }}" href="{{ route('admin.attendance.index', ['work_type' => 'Harian', 'business_unit' => $unitFilter]) }}">Harian</a>
        <a class="btn {{ $workType === 'Borongan' ? 'primary' : '' }}" href="{{ route('admin.attendance.index', ['work_type' => 'Borongan', 'business_unit' => $unitFilter]) }}">Borongan</a>
    </nav>

    <p class="muted">Absensi hari ini: {{ $localNow->format('d/m/Y') }} {{ $timezoneLabel }}</p>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error" role="alert">{{ session('error') }}</p>
    @endif
    @if($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif
    <p class="message" data-attendance-feedback role="status" aria-live="polite" hidden></p>

    @if($canSelectUnit)
        <form action="{{ route('admin.attendance.index') }}" method="GET" class="search-form">
            @if($workType !== '')
                <input type="hidden" name="work_type" value="{{ $workType }}">
            @endif
            <select name="business_unit" aria-label="Filter unit absensi">
                <option value="">Semua Unit</option>
                @foreach($businessUnits as $unit => $label)
                    <option value="{{ $unit }}" @selected($unitFilter === $unit)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn">Filter</button>
        </form>
    @endif

    @if($workType === '')
        <section class="card attendance-type-prompt">
            <h2>Pilih Harian atau Borongan</h2>
            <p class="muted">Gunakan tombol jenis kerja di atas untuk menampilkan karyawan pada alur yang ingin dicatat.</p>
        </section>
    @else
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Karyawan</th>
                    @if($canSelectUnit)
                        <th>Unit</th>
                    @endif
                    @if($workType === 'Harian')
                        <th>Absensi Sesi 1</th>
                        <th>Absensi Sesi 2</th>
                        <th>Lembur</th>
                    @else
                        <th>Absensi Sesi 1 (Pagi)</th>
                        <th>Absensi Sesi 2 (Siang)</th>
                    @endif
                    <th>Tugas aktif / riwayat</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $employee)
                    @php($morningAttendance = $employee->attendances->firstWhere('session', 'morning'))
                    @php($afternoonAttendance = $employee->attendances->firstWhere('session', 'afternoon'))
                    @php($entryWorkType = $workType)
                    @php($canRecordBaliBoronganAttendance = $employee->canRecordBaliBoronganAttendance())
                    <tr id="employee-{{ $employee->id }}">
                        <td>
                            {{ $employee->full_name }}
                            @if($workType === 'Harian' && $employee->business_unit === \App\Models\User::UNIT_FACTORY)
                                @php($todayFinishingAssignments = $employee->finishingAssignments)
                                @if($todayFinishingAssignments->isNotEmpty())
                                    <details class="piecework-assignment-list" open>
                                        <summary aria-label="Lihat detail tugas finishing Harian">
                                            <x-layout.icon name="chevron-down" class="piecework-dropdown-icon" />
                                            <span>Finishing ({{ $todayFinishingAssignments->count() }})</span>
                                        </summary>
                                        @foreach($todayFinishingAssignments as $assignment)
                                            @php($finishingRemaining = $assignment->remaining_quantity)
                                            @php($isFinishingClosed = $assignment->completed_at !== null || $assignment->cancelled_at !== null)
                                            <details class="piecework-assignment-details">
                                                <summary class="piecework-assignment-summary">
                                                    <strong>{{ $assignment->orderItem->product }}</strong>
                                                    <span class="piecework-assignment-status {{ $assignment->cancelled_at !== null ? 'is-cancelled' : ($assignment->completed_at !== null ? 'is-completed' : 'is-open') }}">
                                                        {{ $assignment->cancelled_at !== null ? 'Dibatalkan' : ($assignment->completed_at !== null ? 'Selesai' : 'Berjalan') }}
                                                    </span>
                                                    · SPK #{{ $assignment->orderItem->order->order_id }}
                                                    · {{ $assignment->is_rework
                                                        ? ($assignment->orderItem?->factory_status === 'Service' ? 'Service PF' : 'Finishing ulang')
                                                        : 'Finishing' }}
                                                </summary>
                                                <div class="piecework-assignment-detail">
                                                    <p>
                                                        Mulai {{ $assignment->started_at->copy()->setTimezone($localNow->getTimezone())->format('d/m/Y H:i') }}
                                                        · Selesai {{ $assignment->completed_quantity }}/{{ $assignment->target_quantity }}
                                                        · Sisa {{ $finishingRemaining }}
                                                    </p>
                                                    @if($assignment->completion_note)
                                                        <p>Catatan selesai: {{ $assignment->completion_note }}</p>
                                                    @endif
                                                    @if($assignment->cancellation_note)
                                                        <p>Alasan batal: {{ $assignment->cancellation_note }}</p>
                                                    @endif
                                                    @if(! $isFinishingClosed && $finishingRemaining > 0)
                                                        <button
                                                            type="button"
                                                            class="btn"
                                                            data-piecework-action="cancel"
                                                            data-piecework-context="finishing"
                                                            data-piecework-url="{{ route('admin.finishing-assignments.cancel', $assignment) }}"
                                                            data-piecework-product="{{ $assignment->orderItem->product }}"
                                                            data-piecework-remaining="{{ $finishingRemaining }}"
                                                        >
                                                            Batalkan tugas
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn primary"
                                                            data-piecework-action="complete"
                                                            data-piecework-context="finishing"
                                                            data-piecework-url="{{ route('admin.finishing-assignments.complete', $assignment) }}"
                                                            data-piecework-product="{{ $assignment->orderItem->product }}"
                                                            data-piecework-remaining="{{ $finishingRemaining }}"
                                                        >
                                                            Selesaikan tugas
                                                        </button>
                                                    @endif
                                                </div>
                                            </details>
                                        @endforeach
                                    </details>
                                @endif
                                @php($availableFinishingItems = $finishingOrderItems->filter(fn ($item) => $item->finishing_quantity_available > 0 || $item->rework_quantity_available > 0))
                                @if($availableFinishingItems->isNotEmpty())
                                    <details class="piecework-production-details">
                                        <summary class="btn btn-secondary">+ Tugaskan finishing</summary>
                                        <small>Waktu mulai otomatis saat tugas diberikan. Tugas ini tidak menambah upah harian.</small>
                                        <form action="{{ route('admin.finishing-assignments.store') }}" method="POST" class="piecework-assignment-edit-form" data-finishing-assignment-form>
                                            @csrf
                                            <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                                            <label>
                                                <span>Produk SPK</span>
                                                <select name="order_item_id" required data-finishing-product-select>
                                                    <option value="">Pilih produk</option>
                                                    @foreach($availableFinishingItems as $finishingItem)
                                                        <option
                                                            value="{{ $finishingItem->id }}"
                                                            data-normal="{{ $finishingItem->finishing_quantity_available }}"
                                                            data-rework="{{ $finishingItem->rework_quantity_available }}"
                                                            data-service="{{ $finishingItem->factory_status === 'Service' ? '1' : '0' }}"
                                                        >
                                                            #{{ $finishingItem->order->order_id }} · {{ $finishingItem->product }}
                                                            ({{ $finishingItem->factory_status === 'Service'
                                                                ? 'Service '.$finishingItem->rework_quantity_available.' unit'
                                                                : 'finishing '.$finishingItem->finishing_quantity_available.', rusak '.$finishingItem->rework_quantity_available }})
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label>
                                                <span>Jenis tugas</span>
                                                <select name="is_rework" required data-finishing-type>
                                                    <option value="0">Finishing baru</option>
                                                    <option value="1">Service / perbaikan produk rusak</option>
                                                </select>
                                            </label>
                                            <label>
                                                <span>Jumlah</span>
                                                <input type="number" name="target_quantity" min="1" required data-finishing-quantity>
                                            </label>
                                            <button type="submit" class="btn primary" data-finishing-submit>Mulai tugas</button>
                                        </form>
                                    </details>
                                @endif
                            @endif
                            @if($workType === 'Borongan' && $employee->business_unit === \App\Models\User::UNIT_FACTORY)
                                @php($isOldProductionForm = (string) old('production_form_employee') === (string) $employee->id)
                                @php($productionItems = collect($isOldProductionForm ? old('piecework_items', [[]]) : [[]])->filter(fn ($item) => is_array($item))->values()->all())
                                @php($productionItems = $productionItems === [] ? [[]] : $productionItems)
                                <details class="piecework-production-details" @if($isOldProductionForm) open @endif>
                                    <summary class="btn btn-secondary">+ Tambah Borongan</summary>
                                    <small>Terpisah dari absensi dan harus terkait produk SPK.</small>
                                    <form
                                        action="{{ route('admin.employees.piecework-production.store', $employee) }}"
                                        method="POST"
                                        class="piecework-attendance-form"
                                    >
                                        @csrf
                                        <input type="hidden" name="production_form_employee" value="{{ $employee->id }}">
                                        @include('admin.attendance.partials.piecework-items', [
                                            'employee' => $employee,
                                            'pieceworkOrders' => $pieceworkOrders,
                                            'oldItems' => $productionItems,
                                            'localNow' => $localNow,
                                        ])
                                        <button type="submit" class="btn primary">Catat hasil</button>
                                    </form>
                                </details>
                            @endif
                            @if($workType === 'Borongan' && $employee->business_unit === \App\Models\User::UNIT_BALI)
                                @php($isOldAssignmentForm = (string) old('assignment_form_employee') === (string) $employee->id)
                                <details class="piecework-production-details" @if($isOldAssignmentForm) open @endif>
                                    <summary class="btn btn-secondary">+ Tambah Borongan</summary>
                                    <small>Tetapkan barang, jumlah, harga, dan deadline sebelum mulai dikerjakan.</small>
                                    <form
                                        action="{{ route('admin.employees.piecework-assignments.store', $employee) }}"
                                        method="POST"
                                        class="piecework-assignment-edit-form"
                                    >
                                        @csrf
                                        <input type="hidden" name="assignment_form_employee" value="{{ $employee->id }}">
                                        <label>
                                            <span>Nama barang / tugas</span>
                                            <input
                                                type="text"
                                                name="product_name"
                                                value="{{ $isOldAssignmentForm ? old('product_name') : '' }}"
                                                maxlength="255"
                                                required
                                            >
                                        </label>
                                        <label>
                                            <span>Jumlah target</span>
                                            <input
                                                type="number"
                                                name="target_quantity"
                                                value="{{ $isOldAssignmentForm ? old('target_quantity', 1) : 1 }}"
                                                min="1"
                                                required
                                            >
                                        </label>
                                        <label>
                                            <span>Harga per barang</span>
                                            <input
                                                type="text"
                                                name="unit_price"
                                                value="{{ $isOldAssignmentForm ? old('unit_price') : '' }}"
                                                inputmode="decimal"
                                                data-money-input
                                                autocomplete="off"
                                                required
                                            >
                                        </label>
                                        <label>
                                            <span>Deadline</span>
                                            <input
                                                type="date"
                                                name="target_deadline"
                                                value="{{ $isOldAssignmentForm ? old('target_deadline', $localNow->toDateString()) : $localNow->toDateString() }}"
                                                min="{{ $localNow->toDateString() }}"
                                                required
                                            >
                                        </label>
                                        <button type="submit" class="btn primary">Simpan Borongan</button>
                                    </form>
                                </details>
                                @php($isOldProductionForm = (string) old('production_form_employee') === (string) $employee->id)
                                @php($productionItems = collect($isOldProductionForm ? old('piecework_items', [[]]) : [[]])->filter(fn ($item) => is_array($item))->values()->all())
                                @php($productionItems = $productionItems === [] ? [[]] : $productionItems)
                                <details class="piecework-production-details" @if($isOldProductionForm) open @endif>
                                    <summary class="btn btn-secondary">Catat hasil langsung</summary>
                                    <small>Untuk mencatat barang atau jasa yang sudah selesai tanpa penugasan.</small>
                                    <form
                                        action="{{ route('admin.employees.piecework-production.store', $employee) }}"
                                        method="POST"
                                        class="piecework-attendance-form"
                                    >
                                        @csrf
                                        <input type="hidden" name="production_form_employee" value="{{ $employee->id }}">
                                        @include('admin.attendance.partials.piecework-items', [
                                            'employee' => $employee,
                                            'pieceworkOrders' => collect(),
                                            'oldItems' => $productionItems,
                                            'localNow' => $localNow,
                                        ])
                                        <button type="submit" class="btn primary">Simpan hasil produksi</button>
                                    </form>
                                </details>
                            @endif
                            @if($workType === 'Borongan')
                                @foreach($employee->pieceworkAssignments->filter(fn ($assignment) => $assignment->target_deadline->lt($localNow->toDateString())) as $overdueAssignment)
                                    <span class="attendance-late">
                                        Perlu tindak lanjut: {{ $overdueAssignment->product_name }} (deadline {{ $overdueAssignment->target_deadline->format('d/m/Y') }})
                                    </span>
                                @endforeach
                            @endif
                            @if($workType === 'Borongan')
                                    @php($todayPieceworkItems = $employee->pieceworkItems)
                                    @if($employee->pieceworkAssignments->isNotEmpty() || $todayPieceworkItems->isNotEmpty())
                                        @php($pieceworkCount = $employee->pieceworkAssignments->count() + $todayPieceworkItems->count())
                                        <details class="piecework-assignment-list" open>
                                            <summary aria-label="Lihat detail produk dan tugas Borongan">
                                                <x-layout.icon name="chevron-down" class="piecework-dropdown-icon" />
                                                <span>List Borongan ({{ $pieceworkCount }})</span>
                                            </summary>
                                            @if($todayPieceworkItems->isNotEmpty())
                                                <h4>Hasil Borongan Hari Ini</h4>
                                                @foreach($todayPieceworkItems as $item)
                                                    @php($itemRemainingDays = (int) $localNow->copy()->startOfDay()->diff($item->target_deadline)->format('%r%a'))
                                                    <div class="piecework-assignment-summary">
                                                        <strong>{{ $item->product_name }}</strong>
                                                        · {{ $item->quantity }} barang
                                                        · Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}
                                                        · deadline {{ $item->target_deadline->format('d/m/Y') }}
                                                        @if($itemRemainingDays > 0)
                                                            · Sisa {{ $itemRemainingDays }} hari
                                                        @elseif($itemRemainingDays === 0)
                                                            · Sisa 0 hari
                                                        @else
                                                            · Terlambat · perlu tindak lanjut ({{ abs($itemRemainingDays) }} hari)
                                                        @endif
                                                    </div>
                                                @endforeach
                                            @endif
                                            @if($employee->pieceworkAssignments->isNotEmpty())
                                                <h4>Tugas yang ditugaskan</h4>
                                                @foreach($employee->pieceworkAssignments as $assignment)
                                                    @php($alreadyProduced = (int) $assignment->produced_quantity)
                                                    @php($completedQuantity = (int) $assignment->completed_quantity)
                                                    @php($cancelledQuantity = (int) $assignment->cancelled_quantity)
                                                    @php($remainingQuantity = max($assignment->target_quantity - $alreadyProduced - $completedQuantity - $cancelledQuantity, 0))
                                                    @php($remainingDays = (int) $localNow->copy()->startOfDay()->diff($assignment->target_deadline)->format('%r%a'))
                                                    @php($isAssignmentClosed = $assignment->completed_at !== null || $assignment->cancelled_at !== null)
                                                    <details class="piecework-assignment-details">
                                                        <summary class="piecework-assignment-summary">
                                                            <strong>{{ $assignment->product_name }}</strong>
                                                            <span class="piecework-assignment-status {{ $assignment->cancelled_at !== null ? 'is-cancelled' : ($assignment->completed_at !== null ? 'is-completed' : 'is-open') }}">
                                                                {{ $assignment->cancelled_at !== null ? 'Dibatalkan' : ($assignment->completed_at !== null ? 'Selesai' : ($alreadyProduced + $completedQuantity > 0 ? 'Berjalan' : 'Baru')) }}
                                                            </span>
                                                            @if($assignment->orderItem?->order)
                                                                · SPK #{{ $assignment->orderItem->order->order_id }}
                                                            @endif
                                                            · Deadline {{ $assignment->target_deadline->format('d/m/Y') }}
                                                            @if($remainingDays > 0)
                                                                · Sisa {{ $remainingDays }} hari
                                                            @elseif($remainingDays === 0)
                                                                · Sisa 0 hari
                                                            @else
                                                                · Terlambat · perlu tindak lanjut ({{ abs($remainingDays) }} hari)
                                                            @endif
                                                        </summary>
                                                        <div class="piecework-assignment-detail">
                                                            <p>
                                                                Status:
                                                                {{ $assignment->cancelled_at !== null ? 'Dibatalkan' : ($assignment->completed_at !== null ? 'Selesai' : ($alreadyProduced + $completedQuantity > 0 ? 'Berjalan' : 'Baru')) }}
                                                                @if($assignment->completed_at !== null)
                                                                    · {{ $assignment->completed_at->copy()->setTimezone($localNow->getTimezone())->format('d/m/Y H:i') }}
                                                                @elseif($assignment->cancelled_at !== null)
                                                                    · {{ $assignment->cancelled_at->copy()->setTimezone($localNow->getTimezone())->format('d/m/Y H:i') }}
                                                                @endif
                                                            </p>
                                                            <p>
                                                                Selesai {{ $alreadyProduced + $completedQuantity }} / {{ $assignment->target_quantity }}
                                                                · produksi {{ $alreadyProduced }}
                                                                · ditandai selesai {{ $completedQuantity }}
                                                                · dibatalkan {{ $cancelledQuantity }}
                                                                · sisa target {{ $remainingQuantity }}
                                                                @if($assignment->unit_price !== null)
                                                                    · Rp {{ number_format((float) $assignment->unit_price, 0, ',', '.') }} / barang
                                                                    · Total target Rp {{ number_format($assignment->target_total, 0, ',', '.') }}
                                                                @endif
                                                            </p>
                                                            @if($assignment->completion_note)
                                                                <p>Catatan penyelesaian: {{ $assignment->completion_note }}</p>
                                                            @endif
                                                            @if($assignment->cancellation_note)
                                                                <p>Alasan pembatalan: {{ $assignment->cancellation_note }}</p>
                                                            @endif
                                                            @if($assignment->orderItem?->order)
                                                                <p>SPK #{{ $assignment->orderItem->order->order_id }}</p>
                                                            @endif
                                                            @if($isAssignmentClosed)
                                                                <p>Tugas ini sudah ditutup dan tidak dapat diubah lagi.</p>
                                                            @else
                                                                <p>Jumlah target hanya dapat dikurangi sampai jumlah yang sudah selesai dan dibatalkan.</p>
                                                            <form
                                                                action="{{ route('admin.piecework-assignments.update', $assignment) }}"
                                                                method="POST"
                                                                class="piecework-assignment-edit-form"
                                                            >
                                                                @csrf
                                                                @method('PATCH')
                                                                @if($employee->business_unit === \App\Models\User::UNIT_BALI)
                                                                    <label>
                                                                        <span>Nama barang / tugas</span>
                                                                        <input type="text" name="product_name" value="{{ $assignment->product_name }}" maxlength="255" required>
                                                                    </label>
                                                                @endif
                                                                <label>
                                                                    <span>Jumlah target</span>
                                                                    <input
                                                                        type="number"
                                                                        name="target_quantity"
                                                                        class="piecework-detail-quantity-input"
                                                                        min="{{ max($alreadyProduced + $completedQuantity + $cancelledQuantity, 1) }}"
                                                                        value="{{ $assignment->target_quantity }}"
                                                                        required
                                                                    >
                                                                </label>
                                                                <label>
                                                                    <span>Harga per barang</span>
                                                                    <input
                                                                        type="text"
                                                                        name="unit_price"
                                                                        value="{{ $assignment->unit_price ?? '' }}"
                                                                        inputmode="decimal"
                                                                        data-money-input
                                                                        autocomplete="off"
                                                                        required
                                                                    >
                                                                </label>
                                                                <label>
                                                                    <span>Deadline</span>
                                                                    <input
                                                                        type="date"
                                                                        name="target_deadline"
                                                                        value="{{ $assignment->target_deadline->toDateString() }}"
                                                                        required
                                                                    >
                                                                </label>
                                                                <button type="submit" class="btn">Simpan perubahan</button>
                                                            </form>
                                                            @if($remainingQuantity > 0)
                                                                <form
                                                                    action="{{ route('admin.employees.piecework-production.store', $employee) }}"
                                                                    method="POST"
                                                                    class="piecework-assignment-progress-form"
                                                                >
                                                                    @csrf
                                                                    @if($assignment->orderItem?->order)
                                                                        <input type="hidden" name="piecework_items[0][order_id]" value="{{ $assignment->orderItem->order_id }}">
                                                                        <input type="hidden" name="piecework_items[0][order_item_id]" value="{{ $assignment->order_item_id }}">
                                                                    @endif
                                                                    <input type="hidden" name="piecework_items[0][assignment_id]" value="{{ $assignment->id }}">
                                                                    <input type="hidden" name="piecework_items[0][product_name]" value="{{ $assignment->product_name }}">
                                                                    <input type="hidden" name="piecework_items[0][unit_price]" value="{{ $assignment->unit_price ?? 0 }}">
                                                                    <input type="hidden" name="piecework_items[0][target_deadline]" value="{{ $assignment->target_deadline->toDateString() }}">
                                                                    <label>
                                                                        <span>Jumlah selesai</span>
                                                                        <input class="piecework-detail-quantity-input" type="number" name="piecework_items[0][quantity]" min="1" max="{{ $remainingQuantity }}" value="1" required>
                                                                    </label>
                                                                    <button type="submit" class="btn primary">Catat jumlah selesai</button>
                                                                </form>
                                                                <button
                                                                    type="button"
                                                                    class="btn"
                                                                    data-piecework-action="cancel"
                                                                    data-piecework-url="{{ route('admin.piecework-assignments.cancel-quantity', $assignment) }}"
                                                                    data-piecework-product="{{ $assignment->product_name }}"
                                                                    data-piecework-remaining="{{ $remainingQuantity }}"
                                                                >
                                                                    Batalkan tugas
                                                                </button>
                                                            @endif
                                                            @if($assignment->cancelled_at === null)
                                                                <button
                                                                    type="button"
                                                                    class="btn primary"
                                                                    data-piecework-action="complete"
                                                                    data-piecework-url="{{ route('admin.piecework-assignments.complete', $assignment) }}"
                                                                    data-piecework-product="{{ $assignment->product_name }}"
                                                                    data-piecework-remaining="{{ $remainingQuantity }}"
                                                                >
                                                                    Selesaikan tugas
                                                                </button>
                                                            @endif
                                                            @endif
                                                        </div>
                                                    </details>
                                                @endforeach
                                            @endif
                                        </details>
                                    @endif
                            @endif
                        </td>
                        @if($canSelectUnit)
                            <td>{{ $businessUnits[$employee->business_unit] ?? $employee->business_unit }}</td>
                        @endif
                        @foreach(['morning' => ['1', $morningAttendance], 'afternoon' => ['2', $afternoonAttendance]] as $session => [$label, $attendance])
                            @if(
                                $workType === 'Borongan'
                                && $employee->business_unit === \App\Models\User::UNIT_BALI
                                && ! $canRecordBaliBoronganAttendance
                            )
                                @if($session === 'morning')
                                    <td colspan="2">
                                        <span class="muted">Produksi dicatat melalui tombol pada kolom karyawan.</span>
                                    </td>
                                @endif
                            @else
                        <td data-attendance-cell data-employee-id="{{ $employee->id }}" data-session="{{ $session }}">
                                @if($attendance)
                                    @include('admin.attendance.partials.session-status', ['attendance' => $attendance])
                                    @if($workType === 'Harian' && $attendance->attendance_status === 'Hadir' && $attendance->corrected_at === null)
                                        <form action="{{ route('admin.employees.attendance.cancel', [$employee, $attendance]) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-secondary">Batalkan hadir</button>
                                        </form>
                                    @endif
                                @else
                                    @php($attendanceWindow = $attendanceSchedule->employeeWindow($session))
                                    @php([$openHour, $openMinute] = array_map('intval', explode(':', $attendanceWindow['start'])))
                                    @php([$cutoffHour, $cutoffMinute] = array_map('intval', explode(':', $attendanceWindow['end'])))
                                    @php($cutoff = $localNow->copy()->setTime($cutoffHour, $cutoffMinute))
                                    @php($opensAt = $localNow->copy()->setTime($openHour, $openMinute))
                                    @if($localNow->lt($opensAt))
                                        <small class="muted">Sesi {{ $session === 'morning' ? 'pertama' : 'kedua' }} dibuka pukul {{ $attendanceWindow['start'] }} {{ $timezoneLabel }}</small>
                                    @elseif($localNow->greaterThanOrEqualTo($cutoff))
                                        <span class="muted">Waktu sesi sudah ditutup</span>
                                    @else
                                        <div class="attendance-actions">
                                            <form
                                                action="{{ route('admin.employees.attendance', [$employee, $session]) }}"
                                                method="POST"
                                                class="{{ $entryWorkType === 'Borongan' ? 'piecework-attendance-form' : '' }}"
                                                data-attendance-form
                                                data-employee-id="{{ $employee->id }}"
                                                data-attendance-session="{{ $session }}"
                                                data-attendance-opens="{{ $opensAt->format('Y-m-d\TH:i:sP') }}"
                                                data-attendance-cutoff="{{ $cutoff->format('Y-m-d\TH:i:sP') }}"
                                            >
                                                @csrf
                                                <input type="hidden" name="work_type" value="{{ $entryWorkType }}">
                                                <input type="hidden" name="attendance_status" value="Hadir">
                                                <button type="submit" data-attendance-submit aria-label="Hadir {{ $employee->full_name }} sesi {{ $label }}">Hadir</button>
                                            </form>
                                            @foreach(['Izin', 'Alpa'] as $absenceStatus)
                                                <form
                                                    action="{{ route('admin.employees.attendance', [$employee, $session]) }}"
                                                    method="POST"
                                                    data-attendance-form
                                                    data-employee-id="{{ $employee->id }}"
                                                    data-attendance-session="{{ $session }}"
                                                    data-attendance-opens="{{ $opensAt->format('Y-m-d\TH:i:sP') }}"
                                                    data-attendance-cutoff="{{ $cutoff->format('Y-m-d\TH:i:sP') }}"
                                                >
                                                    @csrf
                                                    <input type="hidden" name="work_type" value="{{ $entryWorkType }}">
                                                    <input type="hidden" name="attendance_status" value="{{ $absenceStatus }}">
                                                    <button type="submit" data-attendance-submit aria-label="{{ $absenceStatus }} {{ $employee->full_name }} sesi {{ $label }}">{{ $absenceStatus }}</button>
                                                </form>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            </td>
                            @endif
                        @endforeach
                        @if($workType === 'Harian')
                            <td>
                                @if($employee->supportsWorkType('Harian'))
                                    @php($isOldOvertimeForm = (string) old('overtime_form_employee') === (string) $employee->id)
                                    @php($todayOvertime = $employee->overtimeEntries->first())
                                    @php($overtimeDate = $isOldOvertimeForm ? old('overtime_date', $localNow->toDateString()) : ($todayOvertime?->overtime_date->toDateString() ?? $localNow->toDateString()))
                                    @php($overtimeHours = $isOldOvertimeForm ? old('overtime_hours') : $todayOvertime?->overtime_hours)
                                    @php($overtimeRate = $isOldOvertimeForm ? old('hourly_rate', $employee->overtime_hourly_rate) : ($todayOvertime?->hourly_rate ?? $employee->overtime_hourly_rate))
                                    <details class="employee-overtime-details" @if($isOldOvertimeForm) open @endif>
                                        <summary class="btn btn-secondary">+ Tambah lembur</summary>
                                        <form
                                            action="{{ route('admin.employees.overtime', $employee) }}"
                                            method="POST"
                                            class="employee-overtime-form"
                                        >
                                            @csrf
                                            <input type="hidden" name="overtime_form_employee" value="{{ $employee->id }}">
                                            <label>
                                                <span>Tanggal</span>
                                                <input type="date" name="overtime_date" value="{{ $overtimeDate }}" min="{{ $employee->started_at->toDateString() }}" max="{{ $localNow->toDateString() }}" required>
                                            </label>
                                            <label>
                                                <span>Jam</span>
                                                <input class="overtime-hours-input" type="number" name="overtime_hours" min="0" max="24" step="0.25" value="{{ $overtimeHours }}" required>
                                            </label>
                                            <label>
                                                <span>Tarif / jam (Rp)</span>
                                                <input type="number" name="hourly_rate" min="0" step="0.01" value="{{ $overtimeRate }}" required>
                                            </label>
                                            <button type="submit">Simpan</button>
                                        </form>
                                    </details>
                                @else
                                    <span class="muted">Jenis Harian diperlukan untuk lembur</span>
                                @endif
                            </td>
                        @endif
                        <td>
                            @if($employee->pieceworkAssignments->isNotEmpty())
                                <a
                                    class="btn btn-secondary"
                                    href="{{ route('admin.attendance.index', ['work_type' => 'Borongan', 'employee_id' => $employee->id]) }}#employee-{{ $employee->id }}"
                                >
                                    Tugas Aktif
                                </a>
                            @endif
                            <a class="icon-button" href="{{ route('admin.employees.attendance-history', $employee) }}" aria-label="Lihat riwayat {{ $employee->full_name }}" title="Lihat riwayat absen">
                                <x-layout.icon name="eye" />
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canSelectUnit ? ($workType === 'Harian' ? 6 : 5) : ($workType === 'Harian' ? 5 : 4) }}">
                            Belum ada karyawan aktif pada jenis kerja ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($workType === 'Harian')
        <p class="muted">Absensi sesi pertama dibuka pukul {{ str_replace(':', '.', $attendanceSchedule->employeeWindow('morning')['start']) }} dan ditutup pukul {{ str_replace(':', '.', $attendanceSchedule->employeeWindow('morning')['end']) }} {{ $timezoneLabel }}; sesi kedua dibuka pukul {{ str_replace(':', '.', $attendanceSchedule->employeeWindow('afternoon')['start']) }} dan ditutup pukul {{ str_replace(':', '.', $attendanceSchedule->employeeWindow('afternoon')['end']) }} {{ $timezoneLabel }}. Lembur dapat dicatat kapan saja, tanpa bergantung pada status kehadiran.</p>
    @else
        <p class="muted">Absensi Borongan tersedia untuk Pilar Factory dan karyawan Pilar Bali yang jenis utamanya dan satu-satunya Borongan. Karyawan Bali dual-type tetap dicatat melalui tugas Borongan tanpa tombol absensi.</p>
    @endif
    {{ $employees->links() }}
    @endif

    <script>
        const nextLocalDay = new Date(@json($localNow->copy()->startOfDay()->addDay()->toIso8601String())).getTime();
        const midnightReload = window.setTimeout(() => window.location.reload(), Math.max(0, nextLocalDay - Date.now()));
        document.addEventListener('pilar:before-navigate', () => window.clearTimeout(midnightReload), { once: true });

        const attendanceTransitions = @json($attendanceTransitions)
            .map((timestamp) => Date.parse(timestamp))
            .filter((timestamp) => timestamp > Date.now());
        if (attendanceTransitions.length > 0) {
            const attendanceReload = window.setTimeout(
                () => window.location.reload(),
                Math.min(...attendanceTransitions) - Date.now(),
            );
            document.addEventListener('pilar:before-navigate', () => window.clearTimeout(attendanceReload), { once: true });
        }

        document.querySelectorAll('[data-attendance-cutoff]').forEach((form) => {
            const opensAt = Date.parse(form.dataset.attendanceOpens);
            const cutoff = Date.parse(form.dataset.attendanceCutoff);
            const updateAttendanceAvailability = () => {
                const isOpen = Date.now() >= opensAt && Date.now() < cutoff;
                form.querySelectorAll('[data-attendance-submit]').forEach((button) => {
                    button.disabled = !isOpen;
                });
            };

            updateAttendanceAvailability();
            if (Date.now() < opensAt) {
                window.setTimeout(updateAttendanceAvailability, opensAt - Date.now());
            }
            window.setTimeout(updateAttendanceAvailability, Math.max(0, cutoff - Date.now()));
        });

        document.querySelectorAll('[data-finishing-assignment-form]').forEach((form) => {
            const product = form.querySelector('[data-finishing-product-select]');
            const type = form.querySelector('[data-finishing-type]');
            const quantity = form.querySelector('[data-finishing-quantity]');
            const submit = form.querySelector('[data-finishing-submit]');
            if (!(product instanceof HTMLSelectElement)
                || !(type instanceof HTMLSelectElement)
                || !(quantity instanceof HTMLInputElement)
                || !(submit instanceof HTMLButtonElement)) {
                return;
            }

            const updateFinishingQuantityLimit = () => {
                const selectedOption = product.selectedOptions[0];
                const normalQuantity = Number.parseInt(selectedOption?.dataset.normal ?? '0', 10) || 0;
                const reworkQuantity = Number.parseInt(selectedOption?.dataset.rework ?? '0', 10) || 0;
                const isService = selectedOption?.dataset.service === '1';
                type.options[0].disabled = isService;
                if (isService) {
                    type.value = '1';
                }
                if (type.value === '0' && normalQuantity === 0 && reworkQuantity > 0) {
                    type.value = '1';
                }

                const available = type.value === '1' ? reworkQuantity : normalQuantity;
                quantity.max = String(available);
                quantity.disabled = available === 0;
                submit.disabled = available === 0;
                if (Number.parseInt(quantity.value, 10) > available) {
                    quantity.value = String(available);
                }
            };

            product.addEventListener('change', updateFinishingQuantityLimit);
            type.addEventListener('change', updateFinishingQuantityLimit);
            updateFinishingQuantityLimit();
        });

    </script>
    @include('admin.attendance.partials.piecework-action-dialog')
@endsection
