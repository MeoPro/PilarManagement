@extends('layouts.admin')

@section('title', 'Daftar Order')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Penjualan</p>
            <h1>Orders</h1>
            <p>Track and manage customer orders.</p>
        </div>
        <div class="order-list-header-actions">
            @if($canManageSales)
                <a href="{{ route('orders.export', array_filter(['search' => $search, 'order_type' => $orderType])) }}" class="btn">
                    <x-layout.icon name="reports" />
                    Export
                </a>
                <a href="{{ route('orders.create') }}" class="btn btn-primary">+ Buat Order</a>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="message" role="status">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="error" role="alert">{{ session('error') }}</div>
    @endif

    <section class="order-summary-grid" aria-label="Ringkasan order">
        @if($canManageSales)
            <article class="order-summary-card is-revenue">
                <span>Total Pendapatan</span>
                <strong>Rp {{ number_format($orderStats->total_revenue ?? 0, 0, '.', ',') }}</strong>
                <x-layout.icon name="reports" class="order-summary-icon" />
            </article>
        @endif
        <article class="order-summary-card is-orders">
            <span>Total Orders</span>
            <strong>{{ number_format($orderStats->total_orders ?? 0) }}</strong>
            <x-layout.icon name="orders" class="order-summary-icon" />
        </article>
        <article class="order-summary-card is-pending">
            <span>{{ $canManageSales ? 'Pending' : 'Dalam Proses' }}</span>
            <strong>{{ number_format($canManageSales ? ($orderStats->pending_orders ?? 0) : ($orderStats->active_orders ?? 0)) }}</strong>
            <x-layout.icon name="calendar" class="order-summary-icon" />
        </article>
        <article class="order-summary-card is-completed">
            <span>Selesai</span>
            <strong>{{ number_format($orderStats->completed_orders ?? 0) }}</strong>
            <x-layout.icon name="dashboard" class="order-summary-icon" />
        </article>
    </section>

    <section class="order-list-panel">
        <form action="{{ route('orders.index') }}" method="GET" class="search-form orders-filter-form">
            <input type="search" name="search" value="{{ $search }}"
                placeholder="{{ $canManageSales ? 'Search orders...' : 'Cari order atau produk...' }}"
                aria-label="Cari order">

            <select name="sort" aria-label="Urutkan order">
                @foreach($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                @endforeach
            </select>

            @if($canManageSales)
                <select name="order_type" aria-label="Filter lokasi tujuan">
                    <option value="">Semua Lokasi Tujuan</option>
                    @foreach($orderTypes as $availableOrderType)
                        <option value="{{ $availableOrderType }}" @selected($orderType === $availableOrderType)>
                            {{ $availableOrderType }}
                        </option>
                    @endforeach
                </select>
            @endif

            <button type="submit" class="btn primary">Cari</button>

            @if($search !== '' || $orderType !== '' || $statusFilter !== '')
                <a href="{{ route('orders.index') }}" class="btn">Reset</a>
            @endif
        </form>

        <nav class="order-status-tabs" aria-label="Filter status order">
            @foreach($statusTabs as $tab)
                @php($tabCount = $tab['value'] === '' ? $statusCounts->sum() : (int) $statusCounts->get($tab['value'], 0))
                @php($tabUrl = route('orders.index', array_filter([
                    'search' => $search,
                    'sort' => $sort,
                    'order_type' => $orderType,
                    'status' => $tab['value'],
                ], fn ($value) => $value !== '' && $value !== null)))
                <a
                    href="{{ $tabUrl }}"
                    class="order-status-tab is-{{ $tab['class'] }} {{ $statusFilter === $tab['value'] ? 'is-active' : '' }}"
                    @if($statusFilter === $tab['value']) aria-current="page" @endif
                >
                    <span class="order-status-dot"></span>
                    {{ $tab['label'] }}
                    <span class="order-status-count">{{ $tabCount }}</span>
                </a>
            @endforeach
        </nav>

        <div id="orders-filter-error" class="error" role="alert" hidden></div>

        @fragment('order-results')
        <div id="order-results">
        @if($canManageSales)
            <div class="table-wrapper orders-table">
                <table>
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Ready</th>
                            <th>Total</th>
                            <th>Pembayaran</th>
                            <th>Status</th>
                            <th>Deadline</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            @php($statusClass = match($order->status) {
                                'None' => 'pending',
                                'Proses Produksi' => 'processing',
                                'Service' => 'service',
                                'Finishing' => 'finishing',
                                'Packing' => 'packing',
                                'Distribusi' => 'distribution',
                                'Selesai' => 'completed',
                                'Cancel' => 'cancelled',
                                default => 'pending',
                            })
                            @php($statusLabel = $order->status === 'None' ? 'New' : ($order->status === 'Proses Produksi' ? 'Processing' : ($order->status === 'Selesai' ? 'Completed' : ($order->status === 'Cancel' ? 'Cancelled' : $order->status))))
                            <tr>
                                <td>
                                    <a class="order-number" href="{{ route('orders.show', $order) }}">SPK {{ $order->order_id }}</a>
                                    <small class="order-date">{{ $order->created_at->format('M j, Y') }}</small>
                                </td>
                                <td>
                                    <div class="order-customer-cell">
                                        <span class="order-customer-avatar">{{ mb_strtoupper(mb_substr($order->guest_name ?: 'T', 0, 2)) }}</span>
                                        <span class="order-customer-copy">
                                            <strong>{{ $order->guest_name }}</strong>
                                            <small>{{ $order->phone ?: 'Pelanggan' }}</small>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <strong>{{ $order->items_count }}</strong>
                                    @if($order->items->isNotEmpty())
                                        <small class="order-item-summary">
                                            {{ $order->items->take(2)->pluck('product')->join(', ') }}
                                            @if($order->items_count > 2)
                                                +{{ $order->items_count - 2 }} lainnya
                                            @endif
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ number_format($order->ready_percentage, 1, ',', '.') }}%</strong>
                                    <small class="order-item-summary">
                                        {{ (int) ($order->ready_units ?? 0) }}/{{ (int) ($order->items_sum_qty ?? 0) }} unit
                                    </small>
                                </td>
                                <td class="order-total">Rp {{ number_format($order->total_order, 0, '.', ',') }}</td>
                                <td>
                                    <strong>{{ number_format($order->paid_percentage, 1, ',', '.') }}%</strong>
                                    <small class="order-item-summary">Dibayar Rp {{ number_format($order->paid_amount, 0, '.', ',') }}</small>
                                    <small class="deadline-remaining">
                                        Sisa Rp {{ number_format($order->amount_remaining, 0, '.', ',') }}
                                        · {{ $order->is_debt ? 'Hutang' : ($order->is_paid ? 'Lunas' : 'Belum lunas') }}
                                    </small>
                                </td>
                                <td>
                                    <span class="order-status-pill is-{{ $statusClass }}">{{ $statusLabel }}</span>
                                    @if($order->is_urgent)
                                        <span class="urgent">URGENT</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $order->deadline?->format('M j, Y') ?? '-' }}
                                    @if($order->deadline && $order->status !== 'Selesai')
                                        @php($daysRemaining = (int) now(auth()->user()->timezone())->startOfDay()->diffInDays($order->deadline->copy()->startOfDay(), false))
                                        <small class="deadline-remaining {{ $daysRemaining < 0 ? 'deadline-overdue' : '' }}">
                                            {{ $daysRemaining > 0 ? $daysRemaining.' hari'.($daysRemaining >= 7 ? ' / '.intdiv($daysRemaining, 7).' minggu' : '').' tersisa' : ($daysRemaining === 0 ? 'Deadline hari ini' : 'Terlambat '.abs($daysRemaining).' hari') }}
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    <div class="order-row-actions">
                                        <a href="{{ route('orders.show', $order) }}" class="icon-button" aria-label="Lihat order {{ $order->order_id }}" title="Lihat order">
                                            <x-layout.icon name="eye" />
                                        </a>
                                        <a href="{{ route('orders.edit', $order) }}" class="icon-button" aria-label="Edit order {{ $order->order_id }}" title="Edit order">
                                            <x-layout.icon name="pencil" />
                                        </a>
                                        @if($order->canBeDeleted())
                                            <form action="{{ route('orders.destroy', $order) }}" method="POST" onsubmit="return confirm('Hapus order ini?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="icon-button delete-button" aria-label="Hapus order {{ $order->order_id }}" title="Hapus order">
                                                    <x-layout.icon name="trash" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9">Belum ada order.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="table-wrapper orders-table">
                <table>
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Produk</th>
                            <th>Ready</th>
                            <th>Target Kirim</th>
                            <th>Status Produksi</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            <tr>
                                <td><a class="order-number" href="{{ route('orders.show', $order) }}">SPK {{ $order->order_id }}</a></td>
                                <td>
                                    @foreach($order->items as $item)
                                        <div>
                                            <strong>{{ $item->product }}</strong> × {{ $item->qty }}
                                            <small>{{ $item->overall_qty_completed }}/{{ $item->qty }} dikerjakan</small>
                                            @if($factoryWorkflow && $item->factory_status === 'Distribusi')
                                                <span class="factory-complete-badge">Selesai di Factory</span>
                                            @else
                                                <small>({{ $item->status }})</small>
                                            @endif
                                        </div>
                                    @endforeach
                                </td>
                                <td>
                                    <strong>{{ number_format($order->ready_percentage, 1, ',', '.') }}%</strong>
                                    <small class="order-item-summary">
                                        {{ (int) ($order->ready_units ?? 0) }}/{{ (int) ($order->items_sum_qty ?? 0) }} unit
                                    </small>
                                </td>
                                <td>{{ $order->deadline?->format('d/m/Y') ?? '-' }}</td>
                                <td>
                                    {{ $order->status === 'None' ? 'New' : $order->status }}
                                    @if($order->is_urgent)
                                        <span class="urgent">URGENT</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="order-row-actions">
                                        <a href="{{ route('orders.show', $order) }}" class="icon-button" aria-label="Lihat order {{ $order->order_id }}" title="Lihat order">
                                            <x-layout.icon name="eye" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Belum ada order.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
            <div class="orders-pagination">{{ $orders->links() }}</div>
        </div>
        @endfragment
    </section>
@endsection
