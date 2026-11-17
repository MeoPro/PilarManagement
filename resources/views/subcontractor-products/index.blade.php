@extends('layouts.admin')

@section('title', 'Produk Subcon')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pilar Factory / Produksi</p>
            <h1>Produk Subcon</h1>
            <p>Kelola produk order yang sedang dikerjakan oleh subkontraktor.</p>
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

    <section class="order-list-panel">
        <form action="{{ route('subcontractor-products.index') }}" method="GET" class="search-form orders-filter-form">
            <input type="search" name="search" value="{{ $search }}" placeholder="Cari order, produk, atau subcon" aria-label="Cari produk subcon">
            <select name="view" aria-label="Tampilan daftar subcon">
                <option value="all" @selected($view === 'all')>Semua data</option>
                <option value="subcontractor" @selected($view === 'subcontractor')>Dikelompokkan per subcon</option>
                <option value="order" @selected($view === 'order')>Dikelompokkan per SPK</option>
                <option value="product" @selected($view === 'product')>Produk satuan</option>
            </select>
            <select name="status" aria-label="Filter status">
                <option value="">Semua Status</option>
                @foreach(['Produksi', 'Proses Kirim', 'Distribusi'] as $availableStatus)
                    <option value="{{ $availableStatus }}" @selected($status === $availableStatus)>{{ $availableStatus }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn primary">Filter</button>
        </form>

        @if($view === 'subcontractor')
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Subcon</th>
                            <th>Order / Produk</th>
                            <th>Barang selesai / dialokasikan</th>
                            <th>Nilai alokasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($subcontractors as $subcontractor)
                            @php($assignedQuantity = $subcontractor->assignments->sum('assigned_quantity'))
                            @php($completedQuantity = $subcontractor->assignments->sum('qty_completed'))
                            @php($assignedValue = $subcontractor->assignments->sum(fn ($assignment) => $assignment->assigned_quantity * (float) $assignment->orderItem->price))
                            <tr>
                                <td>
                                    <a href="{{ route('subcontractors.show', $subcontractor) }}">{{ $subcontractor->name }}</a>
                                    <small>{{ $subcontractor->assignments->count() }} alokasi</small>
                                </td>
                                <td>
                                    @foreach($subcontractor->assignments as $assignment)
                                        <div>{{ $assignment->orderItem->order->order_id }} · {{ $assignment->orderItem->product }}</div>
                                    @endforeach
                                </td>
                                <td>{{ number_format($completedQuantity) }} / {{ number_format($assignedQuantity) }}</td>
                                <td>Rp {{ number_format($assignedValue, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">Belum ada alokasi untuk subcon.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $subcontractors->links() }}
        @elseif($view === 'order')
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>SPK / Order</th>
                            <th>Produk dan subcon</th>
                            <th>Total unit dialokasikan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            <tr>
                                <td>
                                    @if(auth()->user()->canAccessMenu('orders'))
                                        <a href="{{ route('orders.show', $order) }}">{{ $order->order_id }}</a>
                                    @else
                                        {{ $order->order_id }}
                                    @endif
                                    <small>{{ $order->deadline?->format('d/m/Y') ?? 'Tanpa deadline' }}</small>
                                </td>
                                <td>
                                    @foreach($order->items as $item)
                                        @foreach($item->subcontractorAssignments as $assignment)
                                            <div>{{ $item->product }} · {{ $assignment->subcontractor->name }} · {{ $assignment->status }}</div>
                                        @endforeach
                                    @endforeach
                                </td>
                                <td>
                                    @foreach($order->items as $item)
                                        @foreach($item->subcontractorAssignments as $assignment)
                                            <div>{{ $assignment->qty_completed }} / {{ $assignment->assigned_quantity }}</div>
                                        @endforeach
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3">Belum ada order yang dialokasikan ke subcon.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $orders->links() }}
        @elseif($view === 'product')
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>SPK / Order</th>
                            <th>Produk satuan</th>
                            <th>Alokasi ke subcon</th>
                            <th>Jumlah dan progres</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>{{ $product->order->order_id }}</td>
                                <td>{{ $product->product }}<small>{{ $product->category?->name ?? 'Tanpa kategori' }}</small></td>
                                <td>
                                    @foreach($product->subcontractorAssignments as $assignment)
                                        <div>{{ $assignment->subcontractor->name }} · {{ $assignment->status }}</div>
                                    @endforeach
                                </td>
                                <td>
                                    @foreach($product->subcontractorAssignments as $assignment)
                                        <div>{{ $assignment->qty_completed }} / {{ $assignment->assigned_quantity }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4">Belum ada produk satuan yang dialokasikan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $products->links() }}
        @else
            <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>SPK / Order</th>
                        <th>Produk</th>
                        <th>Subcon</th>
                        <th>Jumlah</th>
                        <th>Progres</th>
                        <th>Deadline Subcon</th>
                        <th>Status &amp; Aksi</th>
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
                            </td>
                            <td>{{ $item->product }}<small>{{ $item->category?->name ?? 'Tanpa kategori' }}</small></td>
                            <td>{{ $assignment->subcontractor->name }}</td>
                            <td>{{ $assignment->assigned_quantity }}</td>
                            <td>{{ $assignment->qty_completed }} / {{ $assignment->assigned_quantity }}</td>
                            <td>{{ $assignment->target_deadline?->format('d/m/Y') ?? 'Tanpa deadline' }}</td>
                            <td>
                                <form
                                    action="{{ route('subcontractor-assignments.update', $assignment) }}"
                                    method="POST"
                                    class="compact-form"
                                >
                                    @csrf
                                    @method('PATCH')
                                    <label class="sr-only" for="subcon-progress-{{ $assignment->id }}">Jumlah selesai</label>
                                    <input
                                        id="subcon-progress-{{ $assignment->id }}"
                                        type="number"
                                        name="qty_completed"
                                        min="0"
                                        max="{{ $assignment->assigned_quantity }}"
                                        value="{{ old('qty_completed', $assignment->qty_completed) }}"
                                        required
                                    >
                                    <label class="sr-only" for="subcon-status-{{ $assignment->id }}">Status</label>
                                    <select id="subcon-status-{{ $assignment->id }}" name="status" required>
                                        @php($statuses = $assignment->status === 'Distribusi'
                                            ? ['Distribusi', 'Produksi']
                                            : ($assignment->status === 'Proses Kirim'
                                                ? ['Produksi', 'Proses Kirim', 'Distribusi']
                                                : ['Produksi', 'Proses Kirim']))
                                        @foreach($statuses as $availableStatus)
                                            <option value="{{ $availableStatus }}" @selected(old('status', $assignment->status) === $availableStatus)>{{ $availableStatus }}</option>
                                        @endforeach
                                    </select>
                                    @if($assignment->status === 'Distribusi')
                                        <label for="subcon-rework-comment-{{ $assignment->id }}">Komentar pengembalian</label>
                                        <textarea
                                            id="subcon-rework-comment-{{ $assignment->id }}"
                                            name="comment"
                                            rows="2"
                                            maxlength="2000"
                                            placeholder="Wajib saat dikembalikan ke Produksi"
                                        >{{ old('comment') }}</textarea>
                                    @endif
                                    <label class="sr-only" for="subcon-update-deadline-{{ $assignment->id }}">Deadline subcon</label>
                                    <input
                                        id="subcon-update-deadline-{{ $assignment->id }}"
                                        type="date"
                                        name="target_deadline"
                                        value="{{ old('target_deadline', $assignment->target_deadline?->format('Y-m-d')) }}"
                                    >
                                    <button class="btn product-save-button" type="submit">Simpan</button>
                                </form>
                                @if($assignment->qty_completed < $assignment->assigned_quantity && $assignment->status === 'Produksi')
                                    <form
                                        action="{{ route('subcontractor-assignments.cancel', $assignment) }}"
                                        method="POST"
                                        onsubmit="return confirm('Kembalikan jumlah ini ke antrean Factory?')"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-danger" type="submit">Kembalikan ke Factory</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Belum ada produk yang dialokasikan ke subcon.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            {{ $assignments->links() }}
        @endif
    </section>
@endsection
