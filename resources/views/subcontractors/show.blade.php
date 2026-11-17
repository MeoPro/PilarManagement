@extends('layouts.admin')

@section('title', 'Detail Subkontraktor')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pilar Factory / Subkontraktor</p>
            <h1>{{ $subcontractor->name }}</h1>
            <p>{{ $subcontractor->is_active ? 'Aktif' : 'Nonaktif' }} · {{ $subcontractor->phone ?? 'Nomor telepon belum diisi' }} · {{ $subcontractor->email ?? 'Email belum diisi' }}</p>
            <p class="muted">Barang dihitung selesai saat status produk Distribusi. Order tuntas jika semua produk yang ditugaskan ke subcon ini berstatus Distribusi.</p>
        </div>
        <div class="order-list-header-actions">
            <a class="btn" href="{{ route('subcontractors.index') }}">Kembali</a>
            <a class="btn btn-primary" href="{{ route('subcontractors.edit', $subcontractor) }}">Edit</a>
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif
    @if($errors->any())
        <div class="field-error" role="alert">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($subcontractor->address)
        <section class="card subcontractor-address">
            <h2>Alamat</h2>
            <p>{{ $subcontractor->address }}</p>
        </section>
    @endif

    <div class="order-summary-grid subcontractor-summary">
        <article class="order-summary-card is-completed">
            <span>Keberhasilan Produk</span>
            <strong>{{ number_format($subcontractor->success_percentage, 1, ',', '.') }}%</strong>
        </article>
        <article class="order-summary-card is-orders">
            <span>Order Tuntas di Factory</span>
            <strong>{{ $subcontractor->completed_orders_count }} / {{ $subcontractor->assigned_orders_count }}</strong>
        </article>
        <article class="order-summary-card is-pending">
            <span>Barang Selesai / Ditugaskan</span>
            <strong>{{ number_format($subcontractor->completed_quantity) }} / {{ number_format($subcontractor->assigned_quantity) }}</strong>
        </article>
        <article class="order-summary-card is-revenue">
            <span>Nilai Produk Selesai</span>
            <strong>Rp {{ number_format($subcontractor->completed_value, 0, ',', '.') }}</strong>
        </article>
    </div>

    <section class="card subcontractor-breakdown">
        <div class="order-detail-heading">
            <h2>Rincian per Kategori</h2>
            <span class="muted">Nilai dihitung dari harga order × jumlah barang.</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th>Barang Selesai / Ditugaskan</th>
                        <th>Nilai Selesai</th>
                        <th>Total Nilai Produk</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($categoryBreakdown as $category)
                        <tr>
                            <td>{{ $category->category_name }}</td>
                            <td>{{ number_format($category->completed_quantity) }} / {{ number_format($category->assigned_quantity) }}</td>
                            <td>Rp {{ number_format($category->completed_value, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format($category->assigned_value, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Belum ada produk yang ditugaskan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card subcontractor-breakdown">
        <div class="order-detail-heading">
            <h2>Pekerjaan Berjalan</h2>
            <span class="muted">Jumlah di halaman ini terpisah dari antrean Factory.</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Produk</th>
                        <th>Kategori</th>
                        <th>Jumlah</th>
                        <th>Progres</th>
                        <th>Deadline Subcon</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($assignments as $assignment)
                        @php($item = $assignment->orderItem)
                        <tr>
                            <td>
                                @if(auth()->user()->canAccessMenu('orders'))
                                    <a href="{{ route('orders.show', $item->order) }}">{{ $item->order->order_id }}</a>
                                @else
                                    {{ $item->order->order_id }}
                                @endif
                                <small>{{ $item->order->deadline?->format('d/m/Y') ?? 'Tanpa deadline' }}</small>
                            </td>
                            <td>{{ $item->product }}</td>
                            <td>{{ $item->category?->name ?? '—' }}</td>
                            <td>{{ $assignment->assigned_quantity }}</td>
                            <td>{{ $assignment->qty_completed }} / {{ $assignment->assigned_quantity }}</td>
                            <td>
                                <label class="sr-only" for="assignment-deadline-{{ $assignment->id }}">Deadline subcon</label>
                                <input
                                    id="assignment-deadline-{{ $assignment->id }}"
                                    type="date"
                                    name="target_deadline"
                                    form="update-assignment-{{ $assignment->id }}"
                                    value="{{ old('target_deadline', $assignment->target_deadline?->format('Y-m-d')) }}"
                                >
                            </td>
                            <td>
                                <form id="update-assignment-{{ $assignment->id }}" action="{{ route('subcontractor-assignments.update', $assignment) }}" method="POST" class="compact-form">
                                    @csrf
                                    @method('PATCH')
                                    <label class="sr-only" for="assignment-progress-{{ $assignment->id }}">Jumlah selesai</label>
                                    <input
                                        id="assignment-progress-{{ $assignment->id }}"
                                        type="number"
                                        name="qty_completed"
                                        min="0"
                                        max="{{ $assignment->assigned_quantity }}"
                                        value="{{ old('qty_completed', $assignment->qty_completed) }}"
                                        required
                                    >
                                    <label class="sr-only" for="assignment-status-{{ $assignment->id }}">Status pekerjaan</label>
                                    <select id="assignment-status-{{ $assignment->id }}" name="status" required>
                                        @php($assignmentStatuses = $assignment->status === 'Distribusi'
                                            ? ['Distribusi', 'Produksi']
                                            : ($assignment->status === 'Proses Kirim'
                                                ? ['Produksi', 'Proses Kirim', 'Distribusi']
                                                : ['Produksi', 'Proses Kirim']))
                                        @foreach($assignmentStatuses as $status)
                                            <option value="{{ $status }}" @selected(old('status', $assignment->status) === $status)>{{ $status }}</option>
                                        @endforeach
                                    </select>
                                    @if($assignment->status === 'Distribusi')
                                        <label for="assignment-comment-{{ $assignment->id }}">Komentar jika dikembalikan</label>
                                        <textarea
                                            id="assignment-comment-{{ $assignment->id }}"
                                            name="comment"
                                            rows="2"
                                            maxlength="2000"
                                            placeholder="Wajib saat dikembalikan ke Produksi"
                                        >{{ old('comment') }}</textarea>
                                    @endif
                                    <button class="btn product-save-button" type="submit">Save</button>
                                </form>
                            </td>
                            <td>
                                @if($assignment->qty_completed < $assignment->assigned_quantity && $assignment->status === 'Produksi')
                                    <form
                                        action="{{ route('subcontractor-assignments.cancel', $assignment) }}"
                                        method="POST"
                                        onsubmit="return confirm('Kembalikan sisa jumlah yang belum dikerjakan ke Factory?')"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-danger" type="submit">Kembalikan ke Factory</button>
                                    </form>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Belum ada pekerjaan aktif untuk subcon ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $assignments->links() }}
    </section>
@endsection
