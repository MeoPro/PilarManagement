@extends('layouts.admin')

@section('title', 'Jadwal Absensi')

@section('content')
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Pengaturan</p>
        <h1>Jadwal Absensi</h1>
        <p>Atur jam mulai dan penutupan absensi karyawan serta jendela absensi pertama Kanban.</p>
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

@php
    $scheduleGroups = [
        'Absensi Karyawan' => [
            'employee_morning_start' => 'Mulai sesi pertama',
            'employee_morning_end' => 'Tutup sesi pertama',
            'employee_afternoon_start' => 'Mulai sesi kedua',
            'employee_afternoon_end' => 'Tutup sesi kedua',
        ],
        'Absensi Pertama Kanban Admin' => [
            'kanban_morning_start' => 'Mulai sesi pagi',
            'kanban_morning_end' => 'Tutup sesi pagi',
            'kanban_afternoon_start' => 'Mulai sesi siang jika sesi pagi terlewat',
            'kanban_afternoon_end' => 'Tutup sesi siang',
        ],
    ];
@endphp

<form action="{{ route('admin.attendance-schedule.update') }}" method="POST" class="card attendance-schedule-card">
    @csrf
    @method('PUT')

    @foreach($scheduleGroups as $group => $fields)
        <section class="attendance-schedule-group">
            <h2>{{ $group }}</h2>
            <div class="attendance-schedule-fields">
                @foreach($fields as $field => $label)
                    <label class="form-field attendance-schedule-field" for="{{ $field }}">
                        <span>{{ $label }}</span>
                        <input
                            id="{{ $field }}"
                            type="time"
                            name="{{ $field }}"
                            value="{{ old($field, substr($schedule->{$field}, 0, 5)) }}"
                            required
                        >
                        @error($field)
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </label>
                @endforeach
            </div>
        </section>
    @endforeach

    <p class="muted">Pengaturan ini tidak mengubah waktu upload foto, checkout, atau penutupan papan Kanban.</p>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
    </div>
</form>
@endsection
