@extends('layouts.admin')

@section('title', 'Tambah Kategori')

@section('content')
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Master data</p>
        <h1>Tambah Kategori</h1>
        <p>Buat kategori baru untuk pengelompokan produk.</p>
    </div>
</div>

@if($errors->any())
    <div class="field-error">
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    class="card compact-form"
    action="{{ route('categories.store') }}"
    method="POST"
>

    @csrf

    <div>

        <label for="name">Nama Kategori</label>

        <input
            id="name"
            type="text"
            name="name"
            value="{{ old('name') }}"
            required
        >

    </div>

    <button class="btn-primary" type="submit">Simpan Kategori</button>

    <a class="btn" href="{{ route('categories.index') }}">Batal</a>

</form>
@endsection