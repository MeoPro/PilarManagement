@extends('layouts.admin')

@section('title', 'Akun Admin')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pengaturan</p>
            <h1>Akun Admin</h1>
            <p>Kelola akun admin Pilar Bali dan Pilar Factory.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.users.create') }}">+ Tambah Admin</a>
    </div>

    @if(session('success'))
        <p class="message">{{ session('success') }}</p>
    @endif

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>Unit</th>
                    <th>Menu</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($admins as $admin)
                    <tr>
                        <td>{{ $admin->name }}</td>
                        <td>{{ $admin->email }}</td>
                        <td>{{ $admin->workspaceName() }}</td>
                        <td>
                            @php
                                $menuOptions = \App\Models\User::menuOptionsForUnit($admin->business_unit);
                                $menuKeys = $admin->accessible_menus ?? array_keys($menuOptions);
                            @endphp
                            {{ collect($menuOptions)->only($menuKeys)->values()->implode(', ') }}
                        </td>
                        <td>{{ $admin->is_active ? 'Aktif' : 'Nonaktif' }}</td>
                        <td>
                            <div class="order-row-actions">
                                <a href="{{ route('admin.users.show', $admin) }}" class="icon-button" aria-label="Lihat akun {{ $admin->name }}" title="Lihat akun">
                                    <x-layout.icon name="eye" />
                                </a>
                                <a href="{{ route('admin.users.edit', $admin) }}" class="icon-button" aria-label="Edit akun {{ $admin->name }}" title="Edit akun">
                                    <x-layout.icon name="pencil" />
                                </a>
                                <form action="{{ route('admin.users.destroy', $admin) }}" method="POST" onsubmit="return confirm('Hapus akun admin ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="icon-button delete-button" aria-label="Hapus akun {{ $admin->name }}" title="Hapus akun">
                                        <x-layout.icon name="trash" />
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">Belum ada akun admin.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $admins->links() }}
@endsection
