<strong class="attendance-status-pill attendance-status-{{ strtolower($attendance->attendance_status) }}">
    {{ $attendance->attendance_status }}
</strong>
<span>{{ $attendance->work_type }} ·</span>
@if($attendance->attendance_status === 'Hadir')
    {{ $attendance->attended_at->copy()->setTimezone($timezone)->format('H:i') }}
    @if($attendance->is_late)
        <span class="attendance-late">Terlambat {{ $attendance->late_minutes }} menit</span>
    @else
        <span class="muted">Tepat waktu</span>
    @endif
@elseif($attendance->status_note)
    <span class="muted">{{ $attendance->status_note }}</span>
@endif
@if($attendance->work_type === 'Borongan' && $attendance->pieceworkItems->isNotEmpty())
    <ul class="piecework-attendance-summary">
        @foreach($attendance->pieceworkItems as $item)
            <li>
                {{ $item->product_name }} × {{ $item->quantity }}
                @if($item->piecework_assignment_id)
                    / target {{ $item->assignment?->target_quantity ?? '—' }}
                @endif
                · Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }} / barang
                · Deadline {{ $item->target_deadline->format('d/m/Y') }}
            </li>
        @endforeach
        <li><strong>Total: Rp {{ number_format((float) $attendance->piecework_amount, 0, ',', '.') }}</strong></li>
    </ul>
@endif
<a class="attendance-correction-link" href="{{ route('admin.employees.attendance-history', $employee) }}">Koreksi status</a>
