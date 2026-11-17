@extends('layouts.admin')

@section('title', ($isEditing ? 'Edit' : 'Tambah').' Admin')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pengaturan</p>
            <h1>{{ $isEditing ? 'Edit' : 'Tambah' }} Admin</h1>
            <p>Admin hanya dapat mengakses unit yang ditetapkan.</p>
        </div>
    </div>

    <div class="card employee-form-card">
        @if($errors->any())
            <ul class="error">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form action="{{ $isEditing ? route('admin.users.update', $admin) : route('admin.users.store') }}" method="POST">
            @csrf
            @if($isEditing)
                @method('PUT')
            @endif

            <label for="name">Nama admin</label>
            <input id="name" name="name" value="{{ old('name', $admin->name) }}" maxlength="255" required>

            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $admin->email) }}" maxlength="255" required>

            @php
                $selectedUnit = old('business_unit', $admin->business_unit);
                $selectedUnit = is_string($selectedUnit) ? $selectedUnit : null;
                $menuOptionsByUnit = [
                    \App\Models\User::UNIT_BALI => \App\Models\User::menuOptionsForUnit(\App\Models\User::UNIT_BALI),
                    \App\Models\User::UNIT_FACTORY => \App\Models\User::menuOptionsForUnit(\App\Models\User::UNIT_FACTORY),
                ];
                $selectedMenus = session()->hasOldInput('business_unit')
                    ? old('accessible_menus', [])
                    : ($admin->accessible_menus ?? array_keys(\App\Models\User::menuOptionsForUnit($selectedUnit)));
                $selectedMenus = is_array($selectedMenus) ? $selectedMenus : [];
            @endphp

            <fieldset class="user-unit-access">
                <legend>Unit admin</legend>
                <label for="business_unit">Pilih unit</label>
                <select id="business_unit" name="business_unit" required>
                    <option value="">Pilih Pilar Bali atau Pilar Factory</option>
                    <option value="{{ \App\Models\User::UNIT_BALI }}" @selected($selectedUnit === \App\Models\User::UNIT_BALI)>Pilar Bali</option>
                    <option value="{{ \App\Models\User::UNIT_FACTORY }}" @selected($selectedUnit === \App\Models\User::UNIT_FACTORY)>Pilar Factory</option>
                </select>
                @error('business_unit')
                    <small class="error">{{ $message }}</small>
                @enderror
            </fieldset>

            <div class="user-menu-access">
                <p class="user-menu-access-title">Pilih menu yang boleh diakses</p>
                <p class="muted" data-menu-unit-empty @if(in_array($selectedUnit, array_keys($menuOptionsByUnit), true)) hidden @endif>
                    Pilih unit terlebih dahulu untuk melihat daftar menu.
                </p>
                @foreach($menuOptionsByUnit as $unit => $menuOptions)
                    <fieldset
                        class="user-unit-access"
                        data-menu-unit="{{ $unit }}"
                        @if($selectedUnit !== $unit) hidden @endif
                    >
                        <legend>Menu {{ $unit === \App\Models\User::UNIT_FACTORY ? 'Pilar Factory' : 'Pilar Bali' }}</legend>
                        @foreach($menuOptions as $menu => $label)
                            <label>
                                <input
                                    type="checkbox"
                                    name="accessible_menus[]"
                                    value="{{ $menu }}"
                                    @checked(in_array($menu, $selectedMenus, true))
                                >
                                {{ $label }}
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
                @error('accessible_menus')
                    <small class="error">{{ $message }}</small>
                @enderror
                @error('accessible_menus.*')
                    <small class="error">{{ $message }}</small>
                @enderror
            </div>

            @if($isEditing)
                <label for="is_active">Status akun</label>
                <select id="is_active" name="is_active" required>
                    <option value="1" @selected((string) old('is_active', (int) $admin->is_active) === '1')>Aktif</option>
                    <option value="0" @selected((string) old('is_active', (int) $admin->is_active) === '0')>Nonaktif</option>
                </select>
            @endif

            <label for="password">Password {{ $isEditing ? '(kosongkan jika tidak diubah)' : '' }}</label>
            <input id="password" type="password" name="password" {{ $isEditing ? '' : 'required' }} minlength="8" autocomplete="new-password">

            <label for="password_confirmation">Konfirmasi password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" {{ $isEditing ? '' : 'required' }} minlength="8" autocomplete="new-password">

            <div class="employee-form-actions">
                <button class="btn-primary" type="submit">{{ $isEditing ? 'Simpan Perubahan' : 'Buat Akun' }}</button>
                <a class="btn" href="{{ route('admin.users.index') }}">Kembali</a>
            </div>
        </form>
    </div>
@endsection
