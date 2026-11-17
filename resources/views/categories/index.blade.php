@extends('layouts.admin')

@section('title', 'Kategori Produk')

@section('content')
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Master data</p>
        <h1>Kategori Produk</h1>
        <p>Atur kategori yang digunakan pada detail produk pesanan. Daftar diurutkan A-Z.</p>
    </div>
    <a href="{{ route('categories.create') }}" class="btn btn-primary">+ Tambah Kategori</a>
</div>

@if(session('success'))
    <div style="margin-bottom:20px;color:green;">
        {{ session('success') }}
    </div>
@endif

<div class="table-wrapper">
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>            Kategori</th>
            <th>            Dibuat</th>
            <th width="180">            Aksi</th>
        </tr>
    </thead>

    <tbody>

    @forelse($categories as $category)

        <tr>
            <td>{{ $loop->iteration }}</td>

            <td>
                {{ $category->name }}
            </td>

            <td>
                {{ $category->created_at->format('d M Y') }}
            </td>

            <td>

                <a
                    href="{{ route('categories.edit', $category) }}"
                    class="btn btn-warning"
                >
                    Ubah
                </a>

                <form
                    action="{{ route('categories.destroy', $category) }}"
                    method="POST"
                    style="display:inline;"
                >
                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="btn btn-danger icon-button"
                        aria-label="Hapus kategori"
                        title="Hapus kategori"
                        onclick="return confirm('Hapus category ini?')"
                    >
                        <x-layout.icon name="trash" />
                    </button>
                </form>

            </td>
        </tr>

    @empty

        <tr>
            <td colspan="4">
                Belum ada kategori.
            </td>
        </tr>

    @endforelse

    </tbody>
</table>
</div>
@endsection