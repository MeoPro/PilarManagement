@extends('layouts.admin')

@section('title', 'Riwayat Absensi')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>Riwayat Absensi: {{ $employee->full_name }}</h1>
        </div>
        <div class="order-list-header-actions">
            @if(auth()->user()->canAccessMenu('employees'))
                <a class="btn" href="{{ route('admin.employees.edit', $employee) }}">Ubah Data Karyawan</a>
            @endif
            <a class="btn" href="{{ route('admin.attendance.index') }}">Kembali ke Absensi</a>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error" role="alert">{{ session('error') }}</p>
    @endif
    @if($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="attendance-year-summary employee-cashbond-summary">
        <div>
            <span>Cashbond (sisa hutang)</span>
            <strong>Rp {{ number_format((float) $employee->cashbond, 0, ',', '.') }}</strong>
        </div>
        <small>Saldo cashbond karyawan yang masih belum dibayar.</small>
    </section>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Sesi</th>
                    <th>Status</th>
                    <th>Jenis</th>
                    <th>Rincian Borongan</th>
                    <th>Waktu Absen ({{ $timezoneLabel }})</th>
                    <th>Keterlambatan</th>
                    <th>Lembur</th>
                    @if(auth()->user()->canAccessMenu('attendance'))
                        <th>Koreksi</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $attendance)
                    <tr>
                        <td>{{ $attendance->attendance_date->format('d/m/Y') }}</td>
                        <td>{{ $attendance->session === 'morning' ? 'Sesi 1' : 'Sesi 2' }}</td>
                        <td>
                            <strong class="attendance-status-pill attendance-status-{{ strtolower($attendance->attendance_status) }}">
                                {{ $attendance->attendance_status }}
                            </strong>
                            @if($attendance->status_note)
                                <small>{{ $attendance->status_note }}</small>
                            @endif
                            @if($attendance->correction_reason)
                                <small class="muted">Alasan koreksi: {{ $attendance->correction_reason }}</small>
                            @endif
                        </td>
                        <td>{{ $attendance->work_type }}</td>
                        <td>
                            @forelse($attendance->pieceworkItems as $item)
                                <div>
                                    {{ $item->product_name }} × {{ $item->quantity }}
                                    · Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}
                                    · Deadline {{ $item->target_deadline->format('d/m/Y') }}
                                </div>
                            @empty
                                <span class="muted">-</span>
                            @endforelse
                        </td>
                        <td>{{ $attendance->attendance_status === 'Hadir' ? $attendance->attended_at->copy()->setTimezone($timezone)->format('H:i') : '-' }}</td>
                        <td>
                            @if($attendance->attendance_status === 'Hadir')
                                {{ $attendance->is_late ? 'Terlambat '.$attendance->late_minutes.' menit' : 'Tepat waktu' }}
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $attendance->session === 'afternoon' ? number_format($attendance->overtime_hours, 2, ',', '.').' jam' : '-' }}</td>
                        @if(auth()->user()->canAccessMenu('attendance'))
                            <td>
                                <form
                                    action="{{ route('admin.employees.attendance.correct', [$employee, $attendance]) }}"
                                    method="POST"
                                    class="attendance-correction-form"
                                >
                                    @csrf
                                    @method('PUT')
                                    <label>
                                        <span>Status</span>
                                        <select name="attendance_status" required>
                                            @foreach(['Hadir', 'Izin', 'Alpa'] as $status)
                                                <option value="{{ $status }}" @selected(old('attendance_status', $attendance->attendance_status) === $status)>
                                                    {{ $status }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>
                                        <span>Catatan status (opsional)</span>
                                        <input type="text" name="status_note" value="{{ old('status_note', $attendance->status_note) }}" maxlength="1000">
                                    </label>
                                    <label>
                                        <span>Alasan koreksi</span>
                                        <input type="text" name="correction_reason" value="{{ old('correction_reason') }}" maxlength="1000" required>
                                    </label>
                                    <button type="submit">Simpan koreksi</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ auth()->user()->canAccessMenu('attendance') ? 9 : 8 }}">Belum ada catatan absensi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $attendances->links() }}

    <section class="page-section">
        <div class="page-heading">
            <div>
                <p class="page-eyebrow">Daftar pekerjaan</p>
                <h2>Borongan Berjalan</h2>
            </div>
            @if(auth()->user()->canAccessMenu('attendance'))
                <a
                    class="btn"
                    href="{{ route('admin.attendance.index', ['work_type' => 'Borongan', 'employee_id' => $employee->id]) }}"
                >
                    Kelola Borongan
                </a>
            @endif
        </div>
        <p class="muted">Borongan Berjalan ({{ $pieceworkAssignments->total() }})</p>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Produk / tugas</th>
                        <th>SPK</th>
                        <th>Progress / batal</th>
                        <th>Deadline</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pieceworkAssignments as $assignment)
                        @php($completedQuantity = (int) $assignment->completed_quantity)
                        @php($alreadyProduced = (int) ($assignment->produced_quantity ?? 0))
                        @php($remainingQuantity = max((int) $assignment->target_quantity - $alreadyProduced - $completedQuantity - (int) $assignment->cancelled_quantity, 0))
                        <tr>
                            <td>{{ $assignment->product_name }}</td>
                            <td>{{ $assignment->orderItem?->order?->order_id ? '#'.$assignment->orderItem->order->order_id : '-' }}</td>
                            <td>
                                {{ $alreadyProduced + $completedQuantity }} / {{ $assignment->target_quantity }} selesai
                                · {{ $assignment->cancelled_quantity }} dibatalkan
                                · {{ $remainingQuantity }} tersisa
                            </td>
                            <td>{{ $assignment->target_deadline->format('d/m/Y') }}</td>
                            <td>
                                Berjalan
                                @if($assignment->completion_note)
                                    <small>Catatan selesai: {{ $assignment->completion_note }}</small>
                                @endif
                                @if($assignment->cancellation_note)
                                    <small>Alasan batal: {{ $assignment->cancellation_note }}</small>
                                @endif
                            </td>
                            <td>
                                <details>
                                    <summary class="btn">Edit tugas</summary>
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
                                                min="{{ max($alreadyProduced + $completedQuantity + (int) $assignment->cancelled_quantity, 1) }}"
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
                                            <input type="date" name="target_deadline" value="{{ $assignment->target_deadline->toDateString() }}" required>
                                        </label>
                                        <button type="submit" class="btn">Simpan perubahan</button>
                                    </form>
                                </details>
                                @if($remainingQuantity > 0)
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
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Belum ada borongan yang berjalan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $pieceworkAssignments->links() }}
    </section>
    @include('admin.attendance.partials.piecework-action-dialog')

    <section class="page-section">
        <div class="page-heading">
            <div>
                <p class="page-eyebrow">Produksi tanpa absensi</p>
                <h2>Riwayat Borongan</h2>
                <p class="muted">Hanya hasil yang sudah selesai. Produk yang masih berjalan tersedia di bagian Borongan Berjalan.</p>
            </div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Barang / jasa</th>
                        <th>Jumlah</th>
                        <th>Harga satuan</th>
                        <th>Total</th>
                        <th>Deadline tugas</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pieceworkHistoryItems as $item)
                        <tr>
                            <td>{{ $item->created_at->copy()->setTimezone(auth()->user()->timezone())->format('d/m/Y') }}</td>
                            <td>{{ $item->product_name }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td>Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}</td>
                            <td>{{ $item->target_deadline->format('d/m/Y') }}</td>
                            <td>
                                @if($item->piecework_assignment_id === null && $item->order_item_id === null)
                                    <details>
                                        <summary class="btn">Edit produk</summary>
                                        <form
                                            action="{{ route('admin.employees.piecework-items.update', [$employee, $item]) }}"
                                            method="POST"
                                            class="piecework-assignment-edit-form"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <label for="piecework-item-name-{{ $item->id }}">Barang / jasa</label>
                                            <input
                                                id="piecework-item-name-{{ $item->id }}"
                                                type="text"
                                                name="product_name"
                                                value="{{ $item->product_name }}"
                                                maxlength="255"
                                                required
                                            >
                                            <label for="piecework-item-quantity-{{ $item->id }}">Jumlah</label>
                                            <input
                                                id="piecework-item-quantity-{{ $item->id }}"
                                                type="number"
                                                name="quantity"
                                                value="{{ $item->quantity }}"
                                                min="1"
                                                required
                                            >
                                            <label for="piecework-item-price-{{ $item->id }}">Harga satuan</label>
                                            <input
                                                id="piecework-item-price-{{ $item->id }}"
                                                type="text"
                                                name="unit_price"
                                                value="{{ $item->unit_price }}"
                                                inputmode="decimal"
                                                data-money-input
                                                autocomplete="off"
                                                required
                                            >
                                            <label for="piecework-item-deadline-{{ $item->id }}">Deadline tugas</label>
                                            <input
                                                id="piecework-item-deadline-{{ $item->id }}"
                                                type="date"
                                                name="target_deadline"
                                                value="{{ $item->target_deadline->toDateString() }}"
                                                required
                                            >
                                            <button type="submit" class="btn primary">Simpan perubahan</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Belum ada hasil Borongan tanpa absensi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $pieceworkHistoryItems->links() }}
    </section>

    <section class="page-section">
        <div class="page-heading">
            <div>
                <p class="page-eyebrow">Rincian pembayaran</p>
                <h2>Riwayat Lembur</h2>
            </div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Jam</th>
                        <th>Tarif / jam</th>
                        <th>Total</th>
                        <th>Dicatat oleh</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($overtimeEntries as $overtime)
                        <tr>
                            <td>{{ $overtime->overtime_date->format('d/m/Y') }}</td>
                            <td>{{ number_format((float) $overtime->overtime_hours, 2, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $overtime->hourly_rate, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $overtime->overtime_hours * (float) $overtime->hourly_rate, 0, ',', '.') }}</td>
                            <td>{{ $overtime->recordedBy?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Belum ada catatan lembur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $overtimeEntries->links() }}
    </section>
@endsection
