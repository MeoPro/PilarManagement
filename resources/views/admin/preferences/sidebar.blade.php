@extends('layouts.admin')

@section('title', 'Urutan Sidebar')

@section('content')
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Pengaturan</p>
        <h1>Urutan Sidebar</h1>
        <p>Seret kelompok untuk mengubah urutannya, atau atur menu di dalam kelompok. Anda juga dapat memakai tombol naik dan turun.</p>
    </div>
</div>

@if(session('success'))
    <p class="message">{{ session('success') }}</p>
@endif

<form action="{{ route('admin.sidebar-preferences.update') }}" method="POST" class="card sidebar-order-card" data-sidebar-order-form>
    @csrf
    @method('PUT')

    @error('menu_order')
        <p class="error">{{ $message }}</p>
    @enderror

    @foreach($sidebarMenuGroups as $group => $items)
        <section class="sidebar-order-group" data-sidebar-order-group>
            <header class="sidebar-order-group-header">
                <button
                    type="button"
                    class="sidebar-order-drag-handle"
                    draggable="true"
                    data-sidebar-group-drag-handle
                    aria-label="Geser kelompok {{ $group }}"
                    title="Seret untuk mengubah urutan kelompok"
                >
                    <span aria-hidden="true">⠿</span>
                </button>
                <span class="sidebar-order-position" data-sidebar-group-position>{{ $loop->iteration }}</span>
                <h2 class="sidebar-order-group-label">{{ $group }}</h2>
                <div class="sidebar-order-group-move-controls">
                    <button
                        type="button"
                        class="sidebar-order-move-button"
                        data-sidebar-group-move="up"
                        aria-label="Naikkan kelompok {{ $group }}"
                        title="Naikkan kelompok"
                    >↑</button>
                    <button
                        type="button"
                        class="sidebar-order-move-button"
                        data-sidebar-group-move="down"
                        aria-label="Turunkan kelompok {{ $group }}"
                        title="Turunkan kelompok"
                    >↓</button>
                </div>
            </header>
            <div class="sidebar-order-list" data-sidebar-order-list>
                @foreach($items as $item)
                    <div class="sidebar-order-item" data-sidebar-order-item>
                        <button
                            type="button"
                            class="sidebar-order-drag-handle"
                            draggable="true"
                            data-sidebar-drag-handle
                            aria-label="Geser menu {{ $item['label'] }}"
                            title="Seret untuk mengubah urutan"
                        >
                            <span aria-hidden="true">⠿</span>
                        </button>
                        <span class="sidebar-order-position" data-sidebar-order-position>{{ $item['position'] }}</span>
                        <span class="sidebar-order-label">{{ $item['label'] }}</span>
                        <input
                            type="hidden"
                            name="menu_order[{{ $item['key'] }}]"
                            value="{{ old('menu_order.'.$item['key'], $item['position']) }}"
                            data-sidebar-order-input
                        >
                        <div class="sidebar-order-move-controls">
                            <button
                                type="button"
                                class="sidebar-order-move-button"
                                data-sidebar-move="up"
                                aria-label="Naikkan {{ $item['label'] }}"
                                title="Naikkan"
                            >↑</button>
                            <button
                                type="button"
                                class="sidebar-order-move-button"
                                data-sidebar-move="down"
                                aria-label="Turunkan {{ $item['label'] }}"
                                title="Turunkan"
                            >↓</button>
                        </div>
                        @error('menu_order.'.$item['key'])
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <p class="sidebar-order-status" role="status" aria-live="polite" data-sidebar-order-status></p>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan Urutan</button>
    </div>
</form>
@endsection
