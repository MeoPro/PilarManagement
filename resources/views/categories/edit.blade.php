@extends('layouts.admin')

@section('title', 'Ubah Kategori')

@section('content')
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Master data</p>
        <h1>Ubah Kategori</h1>
        <p>Perbarui nama kategori produk.</p>
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
    action="{{ route('categories.update', $category) }}"
    method="POST"
>

    @csrf
    @method('PUT')

    <div>

        <label for="name">Nama Kategori</label>

        <input
            id="name"
            type="text"
            name="name"
            value="{{ old('name', $category->name) }}"
            required
        >

    </div>

    <button class="btn-primary" type="submit">Simpan Perubahan</button>

    <a class="btn" href="{{ route('categories.index') }}">Batal</a>

</form>
@endsection