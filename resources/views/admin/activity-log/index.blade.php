@extends('layouts.admin')

@section('title', 'Activity Log')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pengawasan</p>
            <h1>Activity Log</h1>
            <p>Aktivitas perubahan dan akses admin tersimpan tanpa merekam password atau isi form sensitif.</p>
        </div>
    </div>

    <form action="{{ route('admin.activity-log.index') }}" method="GET" class="search-form activity-filter-form">
        <select name="user_id" aria-label="Filter admin">
            <option value="">Semua Admin</option>
            @foreach($admins as $admin)
                <option value="{{ $admin->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $admin->id)>
                    {{ $admin->name }} · {{ $admin->business_unit === 'factory' ? 'Factory' : 'Bali' }}
                </option>
            @endforeach
        </select>

        <select name="business_unit" aria-label="Filter unit">
            <option value="">Semua Unit</option>
            <option value="bali" @selected(($filters['business_unit'] ?? '') === 'bali')>Pilar Bali</option>
            <option value="factory" @selected(($filters['business_unit'] ?? '') === 'factory')>Pilar Factory</option>
        </select>

        <select name="action" aria-label="Filter aksi">
            <option value="">Semua Aksi</option>
            @foreach($actions as $action)
                <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
            @endforeach
        </select>

        <label>
            Dari tanggal
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        </label>
        <label>
            Sampai tanggal
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        </label>
        <button type="submit" class="btn primary">Filter</button>
        @if($filters !== [])
            <a class="btn" href="{{ route('admin.activity-log.index') }}">Reset</a>
        @endif
    </form>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Waktu ({{ auth()->user()->timezoneLabel() }})</th>
                    <th>Admin</th>
                    <th>Unit</th>
                    <th>Aksi</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                @forelse($groupedActivities as $group)
                    @php($activity = $group['activity'])
                    <tr>
                        <td>{{ $activity->occurred_at->copy()->setTimezone($timezone)->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $activity->user?->name ?? $activity->actor_name }}</td>
                        <td>{{ $activity->business_unit ? ($activity->business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali') : 'Owner' }}</td>
                        <td>{{ $activity->action }}@if($group['entries'] !== []) ({{ count($group['entries']) }} aktivitas) @endif</td>
                        <td>
                            @if($group['entries'] !== [])
                                <details>
                                    <summary>Lihat rincian</summary>
                                    <ul>
                                        @foreach($group['entries'] as $entry)
                                            <li>{{ $entry->occurred_at->copy()->setTimezone($timezone)->format('d/m/Y H:i:s') }} · {{ $entry->description }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @else
                                {{ $activity->description }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">Tidak ada aktivitas untuk filter ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $activities->links() }}
@endsection
