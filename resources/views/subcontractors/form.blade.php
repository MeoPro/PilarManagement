@extends('layouts.admin')

@section('title', ($isEditing ? 'Edit' : 'Tambah').' Subkontraktor')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pilar Factory</p>
            <h1>{{ $isEditing ? 'Edit' : 'Tambah' }} Subkontraktor</h1>
            <p>Isi informasi kontak dan status kerja subkontraktor.</p>
        </div>
    </div>

    <div class="card employee-form-card">
        @if($errors->any())
            <ul class="error" role="alert">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form action="{{ $isEditing ? route('subcontractors.update', $subcontractor) : route('subcontractors.store') }}" method="POST">
            @csrf
            @if($isEditing)
                @method('PUT')
            @endif

            <label for="subcontractor-name">Nama</label>
            <input id="subcontractor-name" name="name" value="{{ old('name', $subcontractor->name) }}" maxlength="255" required>

            <label for="subcontractor-phone">Nomor telepon</label>
            <input id="subcontractor-phone" name="phone" type="tel" value="{{ old('phone', $subcontractor->phone) }}" maxlength="50">

            <label for="subcontractor-email">Email</label>
            <input id="subcontractor-email" name="email" type="email" value="{{ old('email', $subcontractor->email) }}" maxlength="255">

            <label for="subcontractor-address">Alamat</label>
            <textarea id="subcontractor-address" name="address" rows="3" maxlength="5000">{{ old('address', $subcontractor->address) }}</textarea>

            <label for="subcontractor-status">Status</label>
            <select id="subcontractor-status" name="is_active" required>
                <option value="1" @selected((string) old('is_active', (int) $subcontractor->is_active) === '1')>Aktif</option>
                <option value="0" @selected((string) old('is_active', (int) $subcontractor->is_active) === '0')>Nonaktif</option>
            </select>
            <p class="muted">Subkontraktor nonaktif tidak dapat menerima penugasan baru; riwayat pekerjaan tetap tersimpan.</p>

            <div class="employee-form-actions">
                <button class="btn-primary" type="submit">{{ $isEditing ? 'Simpan Perubahan' : 'Tambah Subkontraktor' }}</button>
                <a class="btn" href="{{ $isEditing ? route('subcontractors.show', $subcontractor) : route('subcontractors.index') }}">Kembali</a>
            </div>
        </form>
    </div>
@endsection
