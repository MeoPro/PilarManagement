@extends('layouts.admin')

@section('title', 'Detail Admin')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pengaturan / Akun Admin</p>
            <h1>{{ $admin->name }}</h1>
            <p>Detail akun admin, unit, dan menu yang dapat diakses.</p>
        </div>
        <div class="order-list-header-actions">
            <a class="btn" href="{{ route('admin.users.index') }}">Kembali</a>
            <a class="btn btn-primary" href="{{ route('admin.users.edit', $admin) }}">Edit Admin</a>
        </div>
    </div>

    <section class="card admin-user-detail">
        <p><strong>Nama:</strong> {{ $admin->name }}</p>
        <p><strong>Email:</strong> {{ $admin->email }}</p>
        <p><strong>Unit:</strong> {{ $admin->workspaceName() }}</p>
        <p><strong>Menu:</strong> {{ $accessibleMenus->implode(', ') ?: 'Tidak ada menu' }}</p>
        <p><strong>Status:</strong> {{ $admin->is_active ? 'Aktif' : 'Nonaktif' }}</p>
        <p><strong>Dibuat:</strong> {{ $admin->created_at->format('d/m/Y H:i') }} WITA</p>
    </section>
@endsection
