@extends('layouts.admin')

@section('title', 'Subkontraktor')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pilar Factory</p>
            <h1>Subkontraktor</h1>
            <p>Pantau progres barang dan nilai pekerjaan berdasarkan kategori.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('subcontractors.create') }}">+ Tambah Subkontraktor</a>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Kontak</th>
                    <th>Status</th>
                    <th>Keberhasilan Produk</th>
                    <th>Order Tuntas di Factory</th>
                    <th>Barang Selesai / Ditugaskan</th>
                    <th>Nilai Produk</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($subcontractors as $subcontractor)
                    <tr>
                        <td><a href="{{ route('subcontractors.show', $subcontractor) }}">{{ $subcontractor->name }}</a></td>
                        <td>
                            {{ $subcontractor->phone ?? '-' }}<br>
                            <small>{{ $subcontractor->email ?? '-' }}</small>
                        </td>
                        <td>{{ $subcontractor->is_active ? 'Aktif' : 'Nonaktif' }}</td>
                        <td>{{ number_format($subcontractor->success_percentage, 1, ',', '.') }}%</td>
                        <td>{{ $subcontractor->completed_orders_count }} / {{ $subcontractor->assigned_orders_count }}</td>
                        <td>{{ number_format($subcontractor->completed_quantity) }} / {{ number_format($subcontractor->assigned_quantity) }}</td>
                        <td>Rp {{ number_format($subcontractor->assigned_value, 0, ',', '.') }}</td>
                        <td>
                            <div class="order-row-actions">
                                <a class="icon-button" href="{{ route('subcontractors.show', $subcontractor) }}" aria-label="Lihat {{ $subcontractor->name }}" title="Lihat detail">
                                    <x-layout.icon name="eye" />
                                </a>
                                <a class="icon-button" href="{{ route('subcontractors.edit', $subcontractor) }}" aria-label="Edit {{ $subcontractor->name }}" title="Edit">
                                    <x-layout.icon name="pencil" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">Belum ada data subkontraktor.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $subcontractors->links() }}
@endsection
