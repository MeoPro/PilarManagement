@extends('layouts.admin')

@section('title', 'Detail Order')

@section('content')
@if($salesWorkflow)
    <div class="order-customer-receipt">
        <div class="page-heading">
            <div>
                <p class="page-eyebrow">Nota Pesanan</p>
                <h1>SPK {{ $order->order_id }}</h1>
                <p>Tanggal: {{ $order->created_at?->format('d/m/Y') ?? '-' }}</p>
            </div>
            <div class="page-heading-actions order-receipt-actions">
                <button class="btn" type="button" onclick="window.print()">Cetak nota</button>
                <a
                    class="btn btn-primary"
                    href="{{ route('orders.edit', $order) }}"
                    aria-label="Edit order {{ $order->order_id }}"
                >Kelola order</a>
                <a class="btn" href="{{ route('orders.index') }}">Kembali</a>
            </div>
        </div>

        @if(session('success'))
            <p class="message order-receipt-notice" role="status">{{ session('success') }}</p>
        @endif
        @if($errors->any())
            <p class="error order-receipt-notice" role="alert">{{ $errors->first() }}</p>
        @endif

        <section class="card order-receipt-customer">
            <h2>Informasi Pelanggan</h2>
            <p><strong>Nama:</strong> {{ $order->guest_name }}</p>
            @if($order->phone)
                <p><strong>Telepon:</strong> {{ $order->phone }}</p>
            @endif
            @if($order->guest?->address)
                <p><strong>Alamat:</strong> {{ $order->guest->address }}</p>
            @endif
            @if($order->order_type)
                <p><strong>Tujuan:</strong> {{ $order->order_type }}</p>
            @endif
            @if($order->deadline)
                <p><strong>Target pengiriman:</strong> {{ $order->deadline->format('d/m/Y') }}</p>
            @endif
            @if($order->status === 'Cancel')
                <p class="order-receipt-cancelled"><strong>Order ini telah dibatalkan.</strong></p>
            @endif
        </section>

        <section class="card order-receipt-items">
            <h2>Rincian Pesanan</h2>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Ukuran</th>
                            <th>Warna</th>
                            <th>Jumlah</th>
                            <th>Harga / unit</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($order->items as $item)
                            <tr>
                                <td>{{ $item->product }}</td>
                                <td>{{ $item->dimensions ?? '—' }}</td>
                                <td>{{ $item->color ?? '—' }}</td>
                                <td>{{ $item->qty }}</td>
                                <td>Rp {{ number_format((float) $item->price, 0, ',', '.') }}</td>
                                <td>Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @php($hasProductPhotos = $order->items->contains(fn ($item) => filled($item->image) || $item->status_evidence_images !== []))
        @if($hasProductPhotos)
            <section class="card order-receipt-photos">
                <h2>Foto Produk</h2>
                @foreach($order->items as $item)
                    @if(filled($item->image) || $item->status_evidence_images !== [])
                        <div class="order-receipt-photo-group">
                            <h3>{{ $item->product }}</h3>
                            <div class="order-receipt-photo-grid">
                                @if(filled($item->image))
                                    <a
                                        class="order-receipt-photo"
                                        href="{{ asset('storage/'.$item->image) }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <span>Foto produk</span>
                                        <img
                                            src="{{ asset('storage/'.$item->image) }}"
                                            alt="Foto produk {{ $item->product }}"
                                        >
                                    </a>
                                @endif
                                @foreach($item->status_evidence_images as $imageIndex => $statusImage)
                                    <a
                                        class="order-receipt-photo"
                                        href="{{ asset('storage/'.$statusImage) }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <span>Foto PB {{ $imageIndex + 1 }}</span>
                                        <img
                                            src="{{ asset('storage/'.$statusImage) }}"
                                            alt="Foto order {{ $item->product }}"
                                        >
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </section>
        @endif

        <section class="card order-receipt-total">
            <p><span>Total pesanan</span><strong>Rp {{ number_format((float) $order->total_order, 0, ',', '.') }}</strong></p>
            <p><span>Sudah dibayar</span><strong>Rp {{ number_format((float) $order->paid_amount, 0, ',', '.') }}</strong></p>
            <p><span>Sisa pembayaran</span><strong>Rp {{ number_format((float) $order->amount_remaining, 0, ',', '.') }}</strong></p>
        </section>
    </div>
@else
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Penjualan / Detail SPK</p>
        <h1>SPK {{ $order->order_id }}</h1>
        <p>Detail pesanan, tenggat, dan progres produksi.</p>
    </div>
    <div class="page-heading-actions">
        @if($salesWorkflow)
            <a
                href="{{ route('orders.edit', $order) }}"
                class="icon-button"
                aria-label="Edit order {{ $order->order_id }}"
                title="Edit order"
            >
                <x-layout.icon name="pencil" />
            </a>
            @if($canRequestOrderCancellation)
                <form
                    action="{{ route('orders.cancellations.store', $order) }}"
                    method="POST"
                    onsubmit="return confirm('Ajukan pembatalan seluruh order ini ke PF? Nilai order akan dikurangi setelah disetujui.')"
                >
                    @csrf
                    <button type="submit" class="btn">Ajukan cancel seluruh order</button>
                </form>
            @endif
            @if($hasPendingOrderCancellation)
                <p class="muted" role="status">Permintaan cancel order sedang menunggu keputusan PF.</p>
            @endif
        @endif
        <a class="btn" href="{{ route('orders.index') }}">Kembali ke Order</a>
    </div>
</div>

@if($factoryWorkflow)
    <div class="order-information-summary">
        <section class="card">
            <h2>Informasi Order</h2>
            <p><strong>SPK:</strong> {{ $order->order_id }}</p>
            <p><strong>Target PF:</strong> {{ $order->factory_deadline?->format('d M Y') ?? 'Belum dimulai' }}</p>
            <p><strong>Target Kirim:</strong> {{ $order->deadline?->format('d M Y') ?? '-' }}</p>
            <p><strong>Status:</strong> {{ $order->is_factory_urgent ? 'URGENT' : ($order->status === 'None' ? 'New' : $order->status) }}</p>
            <p><strong>Terakhir diubah:</strong> {{ $order->last_edit?->copy()->setTimezone(auth()->user()->timezone())->format('d M Y H:i') ?? '-' }} {{ auth()->user()->timezoneLabel() }}</p>
        </section>
    </div>
@elseif($salesWorkflow)
    <div class="order-information-summary">
        <section class="card">
            <h2>Informasi Order</h2>
            <p><strong>SPK:</strong> {{ $order->order_id }}</p>
            <p><strong>Target Kirim:</strong> {{ $order->deadline?->format('d M Y') ?? '-' }}</p>
            <p><strong>Status:</strong> {{ $order->is_urgent ? 'URGENT' : ($order->status === 'None' ? 'New' : $order->status) }}</p>
            <p><strong>Terakhir diubah:</strong> {{ $order->last_edit?->copy()->setTimezone(auth()->user()->timezone())->format('d M Y H:i') ?? '-' }} {{ auth()->user()->timezoneLabel() }}</p>
        </section>
        <section class="card">
            <h2>Informasi Tamu</h2>
            <p><strong>Nama:</strong> {{ $order->guest_name }}</p>
            <p><strong>Sumber:</strong> {{ $order->guest_source ?? '-' }}</p>
            <p><strong>Lokasi Tujuan:</strong> {{ $order->order_type ?? '-' }}</p>
            <p><strong>Telepon:</strong> {{ $order->phone ?? '-' }}</p>
            <p><strong>Alamat:</strong> {{ $order->guest?->address ?? '-' }}</p>
        </section>
    </div>
@endif

<div class="order-detail-layout {{ $factoryWorkflow ? 'factory-order-detail-layout order-create-form' : ($salesWorkflow ? 'sales-order-detail-layout' : '') }}">
    <section class="card order-detail-main {{ $factoryWorkflow ? 'order-form-section' : '' }}">
        <div class="order-detail-heading {{ $factoryWorkflow ? 'order-form-product-heading' : '' }}">
            <h2>{{ $factoryWorkflow ? 'List Produk' : 'Produk' }}</h2>
            <span class="status-badge {{ $salesWorkflow && $order->is_urgent ? 'status-badge-urgent' : '' }}">{{ ($factoryWorkflow ? $order->is_factory_urgent : $order->is_urgent) ? 'URGENT' : ($order->status === 'None' ? 'New' : $order->status) }}</span>
        </div>

        @unless($factoryWorkflow)
            <label for="order-product-status-filter">Filter produk</label>
            <select id="order-product-status-filter" data-order-product-status-filter>
                <option value="">Semua produk ({{ $order->items->count() }})</option>
                @foreach($productStatusCounts as $productStatus => $quantity)
                    <option value="{{ $productStatus }}">{{ $productStatus }} ({{ $quantity }} unit)</option>
                @endforeach
            </select>
        @endunless

        <div class="table-wrapper orders-table {{ $factoryWorkflow ? 'order-form-products-table' : '' }}">
            <table>
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Kategori</th>
                        <th>{{ $factoryWorkflow ? 'Ukuran' : 'Ukuran (P x L x T)' }}</th>
                        <th>Jumlah</th>
                        @if($salesWorkflow)
                            <th>Harga PB /pcs</th>
                            <th>Total PB</th>
                            @if(auth()->user()->isOwner())
                                <th>Harga PF /pcs</th>
                                <th>Total PF</th>
                            @endif
                        @elseif($factoryWorkflow)
                            @if(auth()->user()->isOwner())
                                <th>Harga PB /pcs</th>
                                <th>Total PB</th>
                            @endif
                            <th>Harga PF /pcs</th>
                            <th>Total PF</th>
                        @endif
                        <th>Warna</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th>{{ $factoryWorkflow ? 'Mockup' : 'Mockup dan Gambar Kerja PF' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                        @php($completedQuantity = $item->completed_quantity)
                        @php($productionQuantity = $item->production_quantity)
                        @php($serviceQuantity = $item->service_quantity)
                        <tr
                            data-order-product-row
                            data-order-item-id="{{ $item->id }}"
                            data-order-product-name="{{ $item->product }}"
                            data-status-selesai="{{ $completedQuantity > 0 ? '1' : '0' }}"
                            data-status-produksi="{{ $productionQuantity > 0 ? '1' : '0' }}"
                            data-status-service="{{ $serviceQuantity > 0 ? '1' : '0' }}"
                        >
                            <td>
                                {{ $item->product }}
                                @if($item->sku)
                                    <small>SKU: {{ $item->sku }}</small>
                                @endif
                                @if($salesWorkflow && $order->status !== 'Cancel' && (int) $item->qty > 0)
                                    <details class="order-item-cancellation">
                                        <summary>Permintaan batal</summary>
                                        <form
                                            action="{{ route('order-items.cancellations.store', $item) }}"
                                            method="POST"
                                            class="compact-form"
                                        >
                                            @csrf
                                            <label for="cancel-quantity-{{ $item->id }}">Jumlah</label>
                                            <input
                                                id="cancel-quantity-{{ $item->id }}"
                                                type="number"
                                                name="quantity"
                                                min="1"
                                                max="{{ $item->qty }}"
                                                value="1"
                                                required
                                            >
                                            @if($item->status === 'Ready')
                                                <label for="cancel-stock-unit-{{ $item->id }}">Simpan sebagai stok</label>
                                                <select
                                                    id="cancel-stock-unit-{{ $item->id }}"
                                                    name="stock_business_unit"
                                                    required
                                                >
                                                    <option value="none">Tidak masuk stok</option>
                                                    <option value="bali">Stok PB</option>
                                                    <option value="factory">Stok PF</option>
                                                </select>
                                            @else
                                                <small>PF akan menyetujui, mengalihkan ke stok, atau menolak permintaan ini.</small>
                                            @endif
                                            <button type="submit" class="btn">Kirim permintaan</button>
                                        </form>
                                    </details>
                                @endif
                                @foreach($item->cancellationRequests as $cancellation)
                                    @php($cancellationLabels = [
                                        'pending' => 'Menunggu keputusan PF',
                                        'approved' => 'Disetujui tanpa stok',
                                        'stock' => 'Dialihkan ke stok PF',
                                        'rejected' => 'Ditolak PF',
                                        'ready_stock' => 'Dibatalkan dan masuk stok',
                                        'ready_cancelled' => 'Dibatalkan tanpa stok',
                                    ])
                                    <small>
                                        Pembatalan {{ $cancellation->quantity }} unit · {{ $cancellationLabels[$cancellation->decision] ?? $cancellation->decision }}
                                        @if($cancellation->decision_note)
                                            · {{ $cancellation->decision_note }}
                                        @endif
                                    </small>
                                    @if($factoryWorkflow && $cancellation->decision === 'pending')
                                        @php($completedForCancellation = min((int) $item->qty, $item->overall_qty_completed))
                                        @php($minimumStockQuantity = max(0, $completedForCancellation - ((int) $item->qty - (int) $cancellation->quantity)))
                                        @php($maximumStockQuantity = min($completedForCancellation, (int) $cancellation->quantity))
                                        <form
                                            action="{{ route('order-item-cancellations.update', $cancellation) }}"
                                            method="POST"
                                            class="compact-form order-item-cancellation-decision"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <label for="cancel-decision-{{ $cancellation->id }}">Keputusan PF</label>
                                            <select id="cancel-decision-{{ $cancellation->id }}" name="decision" required>
                                                <option value="approved">Setuju · keluarkan dari order</option>
                                                <option value="stock">Stock · sisihkan hasil jadi ke stok PF</option>
                                                <option value="rejected">Tolak · lanjutkan order</option>
                                            </select>
                                            <label for="cancel-stock-quantity-{{ $cancellation->id }}">Jumlah selesai masuk stok PF</label>
                                            <input
                                                id="cancel-stock-quantity-{{ $cancellation->id }}"
                                                type="number"
                                                name="stock_factory_quantity"
                                                min="{{ $minimumStockQuantity }}"
                                                max="{{ $maximumStockQuantity }}"
                                                value="{{ $maximumStockQuantity }}"
                                            >
                                            <small>Sisa pembatalan yang belum diproduksi akan dibuat sebagai order stok internal PF.</small>
                                            <label for="cancel-note-{{ $cancellation->id }}">Catatan keputusan (opsional)</label>
                                            <input id="cancel-note-{{ $cancellation->id }}" type="text" name="decision_note" maxlength="1000">
                                            <button type="submit" class="btn primary">Simpan keputusan</button>
                                        </form>
                                    @endif
                                @endforeach
                            </td>
                            <td>{{ $item->category?->name ?? '-' }}</td>
                            <td>{{ $item->dimensions ?? '—' }}</td>
                            <td>
                                {{ $item->qty }}
                            </td>
                            @if($salesWorkflow)
                                <td>Rp {{ number_format($item->price, 0, '.', ',') }}</td>
                                <td>Rp {{ number_format($item->subtotal, 0, '.', ',') }}</td>
                                @if(auth()->user()->isOwner())
                                    @php($factorySalePrice = $item->factory_sale_price ?? $item->catalogProduct?->factory_sale_price)
                                    @php($factoryQuantity = $item->source === 'Stock'
                                        ? min((int) $item->stock_factory_quantity, (int) $item->qty)
                                        : (int) $item->qty)
                                    <td>{{ $factorySalePrice !== null ? 'Rp '.number_format((float) $factorySalePrice, 0, ',', '.') : '—' }}</td>
                                    <td>{{ $factorySalePrice !== null && $factoryQuantity > 0 ? 'Rp '.number_format((float) $factorySalePrice * $factoryQuantity, 0, ',', '.') : '—' }}</td>
                                @endif
                            @elseif($factoryWorkflow)
                                @php($factorySalePrice = $item->factory_sale_price ?? $item->catalogProduct?->factory_sale_price)
                                @php($factoryQuantity = $item->source === 'Stock'
                                    ? min((int) $item->stock_factory_quantity, (int) $item->qty)
                                    : (int) $item->qty)
                                @if(auth()->user()->isOwner())
                                    <td>Rp {{ number_format((float) $item->price, 0, ',', '.') }}</td>
                                    <td>Rp {{ number_format((float) $item->price * (int) $item->qty, 0, ',', '.') }}</td>
                                @endif
                                <td data-factory-unit-price>{{ $factorySalePrice !== null ? 'Rp '.number_format((float) $factorySalePrice, 0, ',', '.') : '—' }}</td>
                                <td data-factory-total-price>{{ $factorySalePrice !== null && $factoryQuantity > 0 ? 'Rp '.number_format((float) $factorySalePrice * $factoryQuantity, 0, ',', '.') : '—' }}</td>
                            @endif
                            <td>{{ $item->color ?? '-' }}</td>
                            <td>{{ $item->source }}</td>
                            <td>
                                @if($factoryWorkflow)
                                    <strong data-factory-summary-status>{{ $item->factory_status === 'None' || ($item->status === 'Produksi' && $item->source !== 'Stock' && blank($item->factory_drawing_image)) ? 'New' : ($item->factory_status === 'Ready' ? 'Selesai' : $item->factory_status) }}</strong>
                                @else
                                    {{ $item->status === 'Ready' ? 'Selesai' : $item->status }}
                                @endif
                                @if(!$factoryWorkflow && ($completedQuantity > 0 || $productionQuantity > 0 || $serviceQuantity > 0))
                                    <div class="order-item-quantity-summary" role="group" aria-label="Ringkasan jumlah produk">
                                        @if($completedQuantity > 0)
                                            <span>{{ $completedQuantity }}/{{ $item->qty }} Selesai</span>
                                        @endif
                                        @if($productionQuantity > 0)
                                            <span>{{ $productionQuantity }} Produksi</span>
                                        @endif
                                        @if($serviceQuantity > 0)
                                            <span>{{ $serviceQuantity }} Service</span>
                                        @endif
                                    </div>
                                @endif
                                @if(!$factoryWorkflow && $item->services->isNotEmpty())
                                    <details class="order-item-service-history">
                                        <summary>Riwayat Service</summary>
                                        @foreach($item->services as $service)
                                            <small>
                                                {{ $service->status === 'Ready' ? 'Selesai' : $service->status }}:
                                                {{ $service->quantity }} unit · {{ $service->severity }}
                                                @if($service->note)
                                                    · {{ $service->note }}
                                                @endif
                                            </small>
                                        @endforeach
                                    </details>
                                @endif
                                @if($item->qualityEvents->isNotEmpty())
                                    <details class="order-item-quality-evidence">
                                        <summary>Bukti foto QC/Service ({{ $item->qualityEvents->count() }})</summary>
                                        @foreach($item->qualityEvents as $qualityEvent)
                                            <small>
                                                {{ $qualityEvent->occurred_at?->setTimezone(auth()->user()->timezone())->format('d/m/Y H:i') ?? '-' }} ·
                                                <a href="{{ asset('storage/'.$qualityEvent->image_path) }}" target="_blank" rel="noopener noreferrer">Lihat foto QC</a>
                                            </small>
                                        @endforeach
                                    </details>
                                @endif
                            </td>
                            <td>
                                @php($factoryImage = $item->factory_drawing_image ?? $item->catalogProduct?->factory_mockup)
                                @php($canSeeFactoryImage = $factoryWorkflow || ($salesWorkflow && auth()->user()->isOwner()))
                                @php($hasVisiblePbImages = (! $factoryWorkflow || auth()->user()->isOwner()) && $item->status_evidence_images !== [])
                                @php($hasVisibleFactoryImage = $canSeeFactoryImage && filled($factoryImage))
                                <div class="order-mockup-gallery" data-order-product-gallery>
                                    @foreach((! $factoryWorkflow || auth()->user()->isOwner() ? $item->status_evidence_images : []) as $imageIndex => $statusImage)
                                        <div class="order-mockup-item">
                                            <span>PB {{ $imageIndex + 1 }}</span>
                                            <a href="{{ asset('storage/'.$statusImage) }}" target="_blank" rel="noopener noreferrer">
                                                <img
                                                    class="product-catalog-photo product-catalog-photo-large"
                                                    src="{{ asset('storage/'.$statusImage) }}"
                                                    alt="Mockup PB {{ $item->product }}"
                                                >
                                            </a>
                                            @if($salesWorkflow && $order->status !== 'Cancel')
                                                <form
                                                    action="{{ route('order-products.images.destroy', [$item, 'pb', $imageIndex]) }}"
                                                    method="POST"
                                                    onsubmit="return confirm('Hapus foto PB dari order ini dan katalog produk?')"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="icon-button delete-button" aria-label="Hapus foto PB {{ $imageIndex + 1 }} {{ $item->product }}" title="Hapus foto PB">
                                                        <x-layout.icon name="trash" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if($canSeeFactoryImage)
                                        <div class="order-mockup-item" data-factory-mockup @if(!$factoryImage) hidden @endif>
                                            <span>PF</span>
                                            <a
                                                href="{{ $factoryImage ? asset('storage/'.$factoryImage) : '#' }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                data-factory-image-link
                                                @if(!$factoryImage) hidden @endif
                                            >
                                                <img
                                                    class="product-catalog-photo product-catalog-photo-large"
                                                    src="{{ $factoryImage ? asset('storage/'.$factoryImage) : '' }}"
                                                    alt="Gambar kerja PF {{ $item->product }}"
                                                    data-factory-image
                                                >
                                            </a>
                                            @if($canAssignPiecework && $order->status !== 'Cancel')
                                                <form
                                                    action="{{ route('order-products.images.destroy', [$item, 'pf', 0]) }}"
                                                    method="POST"
                                                    data-factory-image-delete
                                                    data-confirm-message="Hapus gambar PF dari order ini dan katalog produk?"
                                                    @if(!$factoryImage) hidden @endif
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="icon-button delete-button" aria-label="Hapus gambar PF {{ $item->product }}" title="Hapus gambar PF">
                                                        <x-layout.icon name="trash" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                    @if(! $hasVisiblePbImages && ! $hasVisibleFactoryImage)
                                        <span class="muted" data-no-mockup>Belum ada mockup.</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <tr data-order-product-filter-empty hidden>
                        <td colspan="10">Tidak ada produk pada status ini.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if($factoryWorkflow)
            </section>
            <section class="card factory-work-panel order-form-section">
                <div class="order-detail-heading">
                    <h2>Pekerjaan & Data PF</h2>
                </div>
                <div class="table-wrapper orders-table factory-work-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Progres</th>
                                <th>Gambar Kerja PF</th>
                                <th>Harga PF</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($order->items as $item)
                                @php($productionProgress = min($item->overall_qty_completed, (int) $item->qty))
                                @php($finishingProgress = min(
                                    (int) $item->packed_quantity
                                        + (int) $item->quality_good_quantity
                                        + (int) $item->quality_damaged_quantity
                                        + (int) $item->quality_short_quantity,
                                    $productionProgress,
                                ))
                                @php($shippingProgress = min((int) $item->completed_quantity, $finishingProgress))
                                @php($canEditFactoryDetails = $canAssignPiecework
                                    && $order->status !== 'Cancel'
                                    && ($item->source === 'Stock'
                                        || $item->status === 'Produksi'
                                        || ($item->factory_handover_at === null
                                            && in_array($item->factory_status, ['Finishing', 'Packing', 'Proses Kirim'], true)
                                            && in_array($item->status, ['Produksi', 'Finishing', 'Packing', 'Proses Kirim', 'Dalam Perjalanan'], true))
                                        || $item->factory_status === 'Service'))
                                <tr
                                    data-factory-work-row
                                    data-order-item-id="{{ $item->id }}"
                                    data-order-product-name="{{ $item->product }}"
                                >
                                    <td>
                                        <strong>{{ $item->product }}</strong>
                                    </td>
                                    <td>
                                        <div class="factory-stage-progress">
                                        @if($item->factory_status === 'Service')
                                            @php($serviceAssignments = $item->finishingAssignments->where('is_rework', true))
                                            @php($serviceCompleted = (int) $serviceAssignments->sum('completed_quantity'))
                                            <span data-factory-stage="service">Service <strong>{{ $serviceCompleted }}/{{ $serviceCompleted + (int) $item->quality_damaged_quantity }}</strong></span>
                                            <small>Perbaikan Harian · sisa {{ $item->quality_damaged_quantity }}</small>
                                            @if($serviceAssignments->isNotEmpty())
                                                @foreach($serviceAssignments as $serviceAssignment)
                                                    <small>{{ $serviceAssignment->employee?->full_name ?? 'Harian' }} · {{ $serviceAssignment->completed_quantity }}/{{ $serviceAssignment->target_quantity }} selesai</small>
                                                @endforeach
                                            @endif
                                            @if(auth()->user()->canAccessMenu('attendance'))
                                                <a class="btn" href="{{ route('admin.attendance.index', ['work_type' => 'Harian']) }}">Tugaskan Harian</a>
                                            @endif
                                        @endif
                                            <span data-factory-stage="production">Produksi <strong data-factory-stage-count="production">{{ $productionProgress }}/{{ $item->qty }}</strong></span>
                                            @if($productionProgress > 0)
                                                <span data-factory-stage="finishing">Finishing <strong data-factory-stage-count="finishing">{{ $finishingProgress }}/{{ $productionProgress }}</strong></span>
                                            @endif
                                            @if($finishingProgress > 0)
                                                <span data-factory-stage="shipping">Proses Kirim <strong data-factory-stage-count="shipping">{{ $shippingProgress }}/{{ $finishingProgress }}</strong></span>
                                            @endif
                                            @if($order->is_stock_order)
                                                <small>Masih Produksi: {{ max(0, (int) $item->qty - $productionProgress) }} unit · Finishing: {{ $finishingProgress }} unit</small>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        @php($factoryImage = $item->factory_drawing_image ?? $item->catalogProduct?->factory_mockup)
                                        <div data-factory-image-preview>
                                            <a
                                                href="{{ $factoryImage ? asset('storage/'.$factoryImage) : '#' }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                data-factory-image-link
                                                @if(!$factoryImage) hidden @endif
                                            >
                                                <img
                                                    class="product-catalog-photo product-catalog-photo-large"
                                                    src="{{ $factoryImage ? asset('storage/'.$factoryImage) : '' }}"
                                                    alt="Gambar kerja {{ $item->product }}"
                                                    data-factory-image
                                                >
                                            </a>
                                            <span class="muted" data-factory-empty-image @if($factoryImage) hidden @endif>
                                                {{ $item->factory_status === 'Service' ? 'Tidak wajib untuk Service.' : 'Belum ada gambar PF.' }}
                                            </span>
                                        </div>

                                        @if($canEditFactoryDetails)
                                            <form
                                                id="factory-data-{{ $item->id }}"
                                                action="{{ route('order-products.factory-drawing.update', $item) }}"
                                                method="POST"
                                                enctype="multipart/form-data"
                                                class="compact-form"
                                                data-factory-data-form
                                                data-factory-item-id="{{ $item->id }}"
                                            >
                                                @csrf
                                                @method('PATCH')
                                                <label for="factory-drawing-{{ $item->id }}">
                                                    {{ $item->factory_status === 'Service'
                                                        ? 'Unggah / ganti gambar (opsional untuk Service)'
                                                        : ($item->source === 'Stock'
                                                            ? ($factoryImage ? 'Ganti gambar PF (opsional untuk Stock)' : 'Unggah gambar PF (opsional untuk Stock)')
                                                            : ($factoryImage ? 'Ganti gambar PF (opsional)' : 'Unggah gambar kerja PF')) }}
                                                </label>
                                                <input
                                                    id="factory-drawing-{{ $item->id }}"
                                                    type="file"
                                                    name="factory_drawing_image"
                                                    accept="image/jpeg,image/png,image/webp"
                                                >
                                                @if($item->source !== 'Stock' && $item->factory_status === 'None')
                                                    <small>Status menjadi Siap Produksi setelah gambar PF dan harga tersimpan.</small>
                                                @endif
                                                <button type="submit" class="btn primary">Simpan gambar</button>
                                                <p class="message factory-form-feedback" role="status" data-factory-feedback hidden></p>
                                            </form>
                                        @endif
                                    </td>
                                    <td>
                                        @php($factorySalePrice = $item->factory_sale_price ?? $item->catalogProduct?->factory_sale_price)
                                        <div class="factory-price-cell">
                                            <strong data-factory-sale-price>
                                                {{ $factorySalePrice !== null ? 'Rp '.number_format((float) $factorySalePrice, 0, ',', '.') : 'Belum diisi' }}
                                            </strong>
                                            @if($canEditFactoryDetails)
                                                <details class="factory-price-edit">
                                                    <summary class="icon-button" aria-label="Edit harga PF {{ $item->product }}" title="Edit harga PF">
                                                        <x-layout.icon name="pencil" />
                                                    </summary>
                                                    <form
                                                        action="{{ route('order-products.factory-drawing.update', $item) }}"
                                                        method="POST"
                                                        class="compact-form"
                                                        data-factory-data-form
                                                        data-factory-item-id="{{ $item->id }}"
                                                    >
                                                        @csrf
                                                        @method('PATCH')
                                                        <label class="sr-only" for="factory-sale-price-{{ $item->id }}">Harga PF per unit</label>
                                                        <input
                                                            id="factory-sale-price-{{ $item->id }}"
                                                            type="text"
                                                            name="factory_sale_price"
                                                            inputmode="decimal"
                                                            data-money-input
                                                            value="{{ $factorySalePrice !== null ? number_format((float) $factorySalePrice, 2, '.', ',') : '' }}"
                                                        >
                                                        <button type="submit" class="btn primary">Simpan</button>
                                                        <p class="message factory-form-feedback" role="status" data-factory-feedback hidden></p>
                                                    </form>
                                                </details>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
        @endif

        @if($factoryWorkflow)
            <section class="subcontractor-breakdown">
                <h2>Rincian Pekerjaan Factory</h2>
                @if($canAssignPiecework)
                    @if($pieceworkAssignableItems->isNotEmpty())
                        @php($pieceworkToday = now(auth()->user()->timezone())->toDateString())
                        @php($pieceworkOrderDeadline = $order->factory_deadline ?? $order->deadline)
                        @php($pieceworkDefaultDeadline = $pieceworkOrderDeadline !== null && $pieceworkOrderDeadline->toDateString() >= $pieceworkToday
                            ? $pieceworkOrderDeadline->toDateString()
                            : $pieceworkToday)
                        @php($pieceworkFormRows = old('assignments', [[
                            'order_item_id' => '',
                            'target_quantity' => 1,
                            'unit_price' => '',
                            'target_deadline' => $pieceworkDefaultDeadline,
                        ]]))
                        <details class="factory-assignment-disclosure" @if(old('assignments')) open @endif>
                            <summary>
                                <span>+ Tugaskan produk ke borongan</span>
                                <small>Pilih satu karyawan dan tambahkan satu atau beberapa produk.</small>
                            </summary>
                        <form
                            action="{{ route('orders.piecework-assignments.store', $order) }}"
                            method="POST"
                            class="production-status-form piecework-assignment-form factory-piecework-assignment-form"
                            data-factory-piecework-assignment-form
                            data-deadline-today="{{ $pieceworkToday }}"
                            data-default-deadline="{{ $pieceworkDefaultDeadline }}"
                            @if($pieceworkOrderDeadline) data-order-deadline="{{ $pieceworkOrderDeadline->toDateString() }}" @endif
                        >
                            @csrf
                            <label class="factory-piecework-employee">
                                Karyawan Borongan
                                <select name="employee_id" required>
                                    <option value="">Pilih karyawan</option>
                                    @foreach($factoryEmployees as $factoryEmployee)
                                        <option value="{{ $factoryEmployee->id }}" @selected((string) old('employee_id') === (string) $factoryEmployee->id)>
                                            {{ $factoryEmployee->full_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            @error('employee_id')
                                <span class="form-error">{{ $message }}</span>
                            @enderror
                            @if($pieceworkOrderDeadline)
                                <p class="muted factory-piecework-deadline-note">
                                    Deadline tiap produk tidak boleh melewati {{ $pieceworkOrderDeadline->format('d/m/Y') }}.
                                </p>
                            @else
                                <p class="muted factory-piecework-deadline-note">
                                    Order ini belum memiliki deadline, sehingga tanggal penugasan tidak memiliki batas maksimum order.
                                </p>
                            @endif
                            <div class="factory-piecework-item-list" data-factory-piecework-list>
                                @foreach($pieceworkFormRows as $rowIndex => $pieceworkFormRow)
                                    @php($selectedPieceworkItem = $pieceworkAssignableItems->first(fn ($candidate) => (string) $candidate['item']->id === (string) ($pieceworkFormRow['order_item_id'] ?? '')))
                                    <div class="factory-piecework-item-row" data-factory-piecework-item>
                                        <div class="factory-piecework-field">
                                            <label>
                                                Produk
                                                <select
                                                    name="assignments[{{ $rowIndex }}][order_item_id]"
                                                    data-factory-piecework-field="order_item_id"
                                                    data-factory-piecework-product
                                                    required
                                                >
                                                    <option value="">Pilih produk</option>
                                                    @foreach($pieceworkAssignableItems as $candidate)
                                                        <option
                                                            value="{{ $candidate['item']->id }}"
                                                            data-remaining="{{ $candidate['available_quantity'] }}"
                                                            @selected((string) ($pieceworkFormRow['order_item_id'] ?? '') === (string) $candidate['item']->id)
                                                        >
                                                            {{ $candidate['item']->product }} · sisa {{ $candidate['available_quantity'] }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            @error("assignments.{$rowIndex}.order_item_id")
                                                <span class="form-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="factory-piecework-field">
                                            <label>
                                                Jumlah
                                                <input
                                                    type="number"
                                                    name="assignments[{{ $rowIndex }}][target_quantity]"
                                                    value="{{ $pieceworkFormRow['target_quantity'] ?? 1 }}"
                                                    min="1"
                                                    @if($selectedPieceworkItem) max="{{ $selectedPieceworkItem['available_quantity'] }}" @endif
                                                    data-factory-piecework-field="target_quantity"
                                                    data-factory-piecework-quantity
                                                    required
                                                >
                                            </label>
                                            @error("assignments.{$rowIndex}.target_quantity")
                                                <span class="form-error">{{ $message }}</span>
                                            @enderror
                                            <small data-factory-piecework-available></small>
                                        </div>
                                        <div class="factory-piecework-field">
                                            <label>
                                                Harga borongan per unit (Rp)
                                                <input
                                                    type="text"
                                                    name="assignments[{{ $rowIndex }}][unit_price]"
                                                    value="{{ $pieceworkFormRow['unit_price'] ?? '' }}"
                                                    inputmode="decimal"
                                                    autocomplete="off"
                                                    data-money-input
                                                    data-factory-piecework-field="unit_price"
                                                    data-factory-piecework-price
                                                    required
                                                >
                                            </label>
                                            @error("assignments.{$rowIndex}.unit_price")
                                                <span class="form-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="factory-piecework-deadline-field">
                                            <div
                                                class="piecework-deadline-control"
                                                data-deadline-control
                                                data-deadline-today="{{ $pieceworkToday }}"
                                                data-deadline-minimum-days="0"
                                            >
                                                <span class="piecework-deadline-heading">Deadline</span>
                                                <label>
                                                    Tanggal
                                                    <input
                                                        type="date"
                                                        name="assignments[{{ $rowIndex }}][target_deadline]"
                                                        value="{{ $pieceworkFormRow['target_deadline'] ?? $pieceworkDefaultDeadline }}"
                                                        min="{{ $pieceworkToday }}"
                                                        @if($pieceworkOrderDeadline) max="{{ $pieceworkOrderDeadline->toDateString() }}" @endif
                                                        data-deadline-date
                                                        data-factory-piecework-field="target_deadline"
                                                        required
                                                    >
                                                </label>
                                                <label>
                                                    Hari dari hari ini
                                                    <input type="number" min="0" step="1" inputmode="numeric" data-deadline-days>
                                                </label>
                                            </div>
                                            @error("assignments.{$rowIndex}.target_deadline")
                                                <span class="form-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <button type="button" class="btn factory-piecework-remove" data-factory-piecework-remove>
                                            Hapus produk
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <div class="factory-piecework-form-actions">
                                <button type="button" class="btn" data-factory-piecework-add>+ Tambah produk</button>
                                <button class="btn btn-primary" type="submit">Simpan semua penugasan</button>
                            </div>
                        </form>
                        </details>
                    @else
                        <p class="muted">Belum ada produk yang memenuhi syarat untuk ditugaskan ke borongan PF.</p>
                    @endif
                    @foreach($order->items as $item)
                        @if($item->activeSubcontractorAssignments->isNotEmpty())
                            <p class="muted">{{ $item->product }} sudah dialokasikan ke Subcon dan tidak dapat ditugaskan ke borongan PF.</p>
                        @elseif($item->source === 'Stock'
                            && ((int) $item->quality_unchecked_quantity > 0
                                || (int) $item->service_quantity > 0
                                || (int) $item->packed_quantity > 0))
                            <p class="muted">Selesaikan pemeriksaan PB dan Service untuk produk Stock sebelum penugasan PF.</p>
                        @elseif($item->factory_status === 'Service' && (int) $item->quality_damaged_quantity > 0)
                            <p class="muted">Unit rusak berstatus Service ditugaskan kepada karyawan Harian; sisa produksi reguler tetap dapat ditugaskan ke borongan jika tersedia.</p>
                        @elseif(! $item->allowsRegularProductionAssignment() || blank($item->factory_drawing_image))
                            <p class="muted">{{ $item->product }} dapat ditugaskan jika masih ada sisa produksi reguler dan gambar kerja PF.</p>
                        @endif
                    @endforeach
                @endif
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Karyawan</th>
                                <th>Progres / Target</th>
                                <th>Deadline</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php($hasPieceworkAssignments = false)
                            @foreach($order->items as $item)
                                @foreach($item->pieceworkAssignments as $assignment)
                                    @php($hasPieceworkAssignments = true)
                                    <tr>
                                        <td>{{ $item->product }}</td>
                                        <td>{{ $assignment->employee?->full_name ?? 'Karyawan tidak tersedia' }}</td>
                                        <td>{{ (int) $assignment->produced_quantity + (int) $assignment->completed_quantity }} selesai / {{ $assignment->target_quantity }} · {{ $assignment->cancelled_quantity }} dibatalkan</td>
                                        <td>{{ $assignment->target_deadline->format('d/m/Y') }}</td>
                                        <td>
                                            @if($assignment->completed_at)
                                                <span class="factory-complete-badge">Selesai</span>
                                            @elseif($assignment->cancelled_at)
                                                <span class="attendance-late">Dibatalkan</span>
                                            @else
                                                <span>{{ (int) $assignment->produced_quantity + (int) $assignment->completed_quantity > 0 ? 'Berjalan' : 'Baru' }}</span>
                                                @if($assignment->target_deadline->lt(today(auth()->user()->timezone())))
                                                    <span class="attendance-late">Terlambat · perlu tindak lanjut</span>
                                                @endif
                                            @endif
                                            @if($assignment->completion_note)
                                                <small>Catatan selesai: {{ $assignment->completion_note }}</small>
                                            @endif
                                            @if($assignment->cancellation_note)
                                                <small>Alasan batal: {{ $assignment->cancellation_note }}</small>
                                            @endif
                                            @if(!$assignment->completed_at && !$assignment->cancelled_at && $canAssignPiecework)
                                                <details class="piecework-assignment-edit">
                                                    <summary class="btn">Edit penugasan</summary>
                                                    <form
                                                        action="{{ route('piecework-assignments.update', $assignment) }}"
                                                        method="POST"
                                                        class="production-status-form"
                                                    >
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="assignment_form_id" value="{{ $assignment->id }}">
                                                        <label for="assignment-employee-{{ $assignment->id }}">Karyawan</label>
                                                        <select id="assignment-employee-{{ $assignment->id }}" name="employee_id" required>
                                                            <option value="">Pilih karyawan</option>
                                                            @foreach($factoryEmployees as $factoryEmployee)
                                                                <option
                                                                    value="{{ $factoryEmployee->id }}"
                                                                    @selected((string) ($assignment->id === (int) old('assignment_form_id') ? old('employee_id', $assignment->employee_id) : $assignment->employee_id) === (string) $factoryEmployee->id)
                                                                >
                                                                    {{ $factoryEmployee->full_name }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                        <label for="assignment-quantity-{{ $assignment->id }}">Jumlah target</label>
                                                        <input
                                                            id="assignment-quantity-{{ $assignment->id }}"
                                                            type="number"
                                                            name="target_quantity"
                                                            min="{{ max((int) $assignment->produced_quantity + (int) $assignment->completed_quantity + (int) $assignment->cancelled_quantity, 1) }}"
                                                            value="{{ $assignment->id === (int) old('assignment_form_id') ? old('target_quantity', $assignment->target_quantity) : $assignment->target_quantity }}"
                                                            required
                                                        >
                                                        <label for="assignment-price-{{ $assignment->id }}">Harga per barang</label>
                                                        <input
                                                            id="assignment-price-{{ $assignment->id }}"
                                                            type="text"
                                                            name="unit_price"
                                                            inputmode="decimal"
                                                            autocomplete="off"
                                                            data-money-input
                                                            value="{{ $assignment->id === (int) old('assignment_form_id') ? old('unit_price', $assignment->unit_price) : $assignment->unit_price }}"
                                                            required
                                                        >
                                                        <label for="assignment-deadline-{{ $assignment->id }}">Deadline</label>
                                                        <input
                                                            id="assignment-deadline-{{ $assignment->id }}"
                                                            type="date"
                                                            name="target_deadline"
                                                            value="{{ $assignment->id === (int) old('assignment_form_id') ? old('target_deadline', $assignment->target_deadline->toDateString()) : $assignment->target_deadline->toDateString() }}"
                                                            required
                                                        >
                                                        <button class="btn" type="submit">Simpan perubahan</button>
                                                    </form>
                                                </details>
                                                @php($assignmentRemainingQuantity = max((int) $assignment->target_quantity - (int) $assignment->produced_quantity - (int) $assignment->completed_quantity - (int) $assignment->cancelled_quantity, 0))
                                                <button
                                                    type="button"
                                                    class="btn primary"
                                                    data-piecework-action="complete"
                                                    data-piecework-url="{{ route('piecework-assignments.complete', $assignment) }}"
                                                    data-piecework-product="{{ $assignment->product_name }}"
                                                    data-piecework-remaining="{{ $assignmentRemainingQuantity }}"
                                                >
                                                    Selesaikan tugas
                                                </button>
                                            @endif
                                            @if((int) $assignment->completed_quantity > 0 && $canAssignPiecework)
                                                <details class="piecework-assignment-edit">
                                                    <summary class="btn">Catat upah borongan</summary>
                                                    <form
                                                        action="{{ route('piecework-assignments.rate.update', $assignment) }}"
                                                        method="POST"
                                                        class="compact-form"
                                                    >
                                                        @csrf
                                                        @method('PATCH')
                                                        <label for="assignment-pay-rate-{{ $assignment->id }}">Harga borongan per unit (Rp)</label>
                                                        <input
                                                            id="assignment-pay-rate-{{ $assignment->id }}"
                                                            type="text"
                                                            name="unit_price"
                                                            value="{{ $assignment->unit_price !== null ? number_format((float) $assignment->unit_price, 2, '.', ',') : '' }}"
                                                            inputmode="decimal"
                                                            autocomplete="off"
                                                            data-money-input
                                                            required
                                                        >
                                                        <small>{{ $assignment->completed_quantity }} unit yang telah diselesaikan akan dimasukkan ke penggajian.</small>
                                                        <button class="btn primary" type="submit">Simpan upah</button>
                                                    </form>
                                                </details>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                            @unless($hasPieceworkAssignments)
                                <tr><td colspan="5">Belum ada penugasan borongan untuk produk order ini.</td></tr>
                            @endunless
                        </tbody>
                    </table>
                </div>
                <h3>Catatan jumlah yang sudah dikerjakan</h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Karyawan</th>
                                <th>Jumlah</th>
                                <th>Deadline</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php($hasPieceworkRows = false)
                            @foreach($order->items as $item)
                                @foreach($item->pieceworkItems as $pieceworkItem)
                                    @php($hasPieceworkRows = true)
                                    <tr>
                                        <td>{{ $item->product }}</td>
                                        <td>{{ $pieceworkItem->employee?->full_name ?? $pieceworkItem->attendance?->employee?->full_name ?? 'Karyawan tidak tersedia' }}</td>
                                        <td>{{ $pieceworkItem->quantity }}</td>
                                        <td>{{ $pieceworkItem->target_deadline->format('d/m/Y') }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                            @unless($hasPieceworkRows)
                                <tr><td colspan="4">Belum ada catatan jumlah borongan untuk produk order ini.</td></tr>
                            @endunless
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="subcontractor-breakdown">
                <h2>Rincian Produk Subcon</h2>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Subcon</th>
                                <th>Jumlah</th>
                                <th>Progres</th>
                                <th>Deadline</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php($hasSubcontractorRows = false)
                            @foreach($order->items as $item)
                                @foreach($item->activeSubcontractorAssignments as $assignment)
                                    @php($hasSubcontractorRows = true)
                                    <tr>
                                        <td>{{ $item->product }}</td>
                                        <td>{{ $assignment->subcontractor->name }}</td>
                                        <td>{{ $assignment->assigned_quantity }}</td>
                                        <td>{{ $assignment->qty_completed }} / {{ $assignment->assigned_quantity }}</td>
                                        <td>{{ $assignment->target_deadline?->format('d/m/Y') ?? 'Tanpa deadline' }}</td>
                                        <td>{{ $assignment->status }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                            @unless($hasSubcontractorRows)
                                <tr><td colspan="6">Belum ada produk yang dialokasikan ke subcon.</td></tr>
                            @endunless
                        </tbody>
                    </table>
                </div>
            </section>
            </section>
        @endif

        @if($salesWorkflow)
            <h2 class="order-detail-total">Total: Rp {{ number_format($order->total_order, 0, '.', ',') }}</h2>
            <section class="order-payment-summary">
                <h3>Pembayaran</h3>
                <p><strong>DP / Dibayar:</strong> {{ number_format($order->paid_percentage, 1, ',', '.') }}% · Rp {{ number_format($order->paid_amount, 0, '.', ',') }}</p>
                <p><strong>Sisa pembayaran:</strong> Rp {{ number_format($order->amount_remaining, 0, '.', ',') }}</p>
                <p><strong>Status:</strong> {{ $order->is_paid ? 'Sudah dibayar' : 'Belum dibayar' }}</p>

                @if((float) $order->opening_paid_amount > 0)
                    <p class="payment-opening-note">
                        <strong>Saldo awal tanpa tanggal:</strong>
                        Rp {{ number_format((float) $order->opening_paid_amount, 0, ',', '.') }}
                        <small>Pembayaran ini sudah termasuk di total dibayar, tetapi tanggal cicilannya belum tercatat.</small>
                    </p>
                @endif

                <h4>Riwayat cicilan</h4>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Jumlah</th>
                                <th>Catatan</th>
                                <th>Diterima oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($order->payments as $payment)
                                <tr>
                                    <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                                    <td>Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</td>
                                    <td>{{ $payment->note ?? '—' }}</td>
                                    <td>{{ $payment->receivedBy?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4">Belum ada cicilan bertanggal.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($order->status !== 'Cancel')
                    <form
                        action="{{ route('orders.payments.store', $order) }}"
                        method="POST"
                        class="order-payment-entry"
                    >
                        @csrf
                        <h4>Catat pembayaran</h4>
                        <label for="payment-amount">Jumlah diterima (sisa Rp {{ number_format($order->amount_remaining, 0, ',', '.') }})</label>
                        <input
                            id="payment-amount"
                            type="text"
                            name="amount"
                            inputmode="decimal"
                            data-money-input
                            value="{{ old('amount') }}"
                            placeholder="Contoh: 100,000"
                            required
                        >
                        @error('amount')
                            <small class="field-error" role="alert">{{ $message }}</small>
                        @enderror
                        <label for="payment-paid-at">Tanggal pembayaran</label>
                        <input
                            id="payment-paid-at"
                            type="date"
                            name="paid_at"
                            value="{{ old('paid_at', now(auth()->user()->timezone())->toDateString()) }}"
                            max="{{ now(auth()->user()->timezone())->toDateString() }}"
                            required
                        >
                        @error('paid_at')
                            <small class="field-error" role="alert">{{ $message }}</small>
                        @enderror
                        <label for="payment-note">Catatan (opsional)</label>
                        <textarea id="payment-note" name="note" rows="2" maxlength="1000">{{ old('note') }}</textarea>
                        @error('note')
                            <small class="field-error" role="alert">{{ $message }}</small>
                        @enderror
                        <button class="btn btn-primary" type="submit">Simpan cicilan</button>
                    </form>
                @endif
            </section>
        @endif
    @unless($factoryWorkflow)
    </section>
    @endunless

    <aside class="order-detail-sidebar">
        @if((!$salesWorkflow && !$factoryWorkflow) || $order->pending_deadline || ($canAssignSubcontractors && $order->status !== 'Cancel'))
        <section class="card">
            @if(!$salesWorkflow && !$factoryWorkflow)
                <h2>Informasi Order</h2>
                <p><strong>SPK:</strong> {{ $order->order_id }}</p>
                <p><strong>Target Kirim:</strong> {{ $order->deadline?->format('d M Y') ?? '-' }}</p>
                <p><strong>Status:</strong> {{ $order->is_urgent ? 'URGENT' : ($order->status === 'None' ? 'New' : $order->status) }}</p>
                <p><strong>Terakhir diubah:</strong> {{ $order->last_edit?->copy()->setTimezone(auth()->user()->timezone())->format('d M Y H:i') ?? '-' }} {{ auth()->user()->timezoneLabel() }}</p>
            @else
                <h2>Aksi Order</h2>
            @endif

            @if($order->pending_deadline)
                <section class="order-deadline-request" aria-labelledby="pending-deadline-heading">
                    <h3 id="pending-deadline-heading">Pengajuan Percepatan Deadline</h3>
                    <p><strong>Deadline diajukan:</strong> {{ $order->pending_deadline->format('d M Y') }}</p>
                    <p><strong>Diajukan oleh:</strong> {{ $order->pendingDeadlineRequestedBy?->name ?? 'Admin PB' }}</p>
                    <p><strong>Alasan:</strong> {{ $order->pending_deadline_reason ?? '-' }}</p>

                    @if($canReviewDeadlineChange)
                        <form action="{{ route('orders.deadline-change.approve', $order) }}" method="POST" class="production-status-form">
                            @csrf
                            @method('PATCH')
                            <button class="btn btn-success" type="submit">Setujui deadline</button>
                        </form>
                        <form action="{{ route('orders.deadline-change.reject', $order) }}" method="POST" class="production-status-form">
                            @csrf
                            @method('PATCH')
                            <label for="deadline-decision-note">Catatan penolakan (opsional)</label>
                            <textarea id="deadline-decision-note" name="decision_note" maxlength="1000" rows="2"></textarea>
                            <button class="btn btn-danger" type="submit">Tolak pengajuan</button>
                        </form>
                    @endif
                </section>
            @endif

            @if($canAssignSubcontractors && $order->status !== 'Cancel')
                @if($hasEmployeePieceworkAssignments)
                    <p class="muted" role="status">
                        Ada produk SPK yang sudah ditugaskan ke karyawan borongan. Pilih subcon per produk melalui
                        @if($canAssignPiecework)
                            <a href="{{ route('order-products.index') }}">daftar Produk Order</a>.
                        @else
                            daftar Produk Order; minta akses menu tersebut jika belum tersedia.
                        @endif
                    </p>
                @else
                    <form action="{{ route('orders.subcontractor-assignments.store', $order) }}" method="POST" class="production-status-form">
                        @csrf
                        <h3>Alokasikan seluruh sisa order</h3>
                        <p class="muted">Setiap produk harus memiliki gambar kerja PF atau Mockup PF (gambar kedua), serta harga jual per unit ke PB yang sudah disimpan.</p>
                        <label for="order-subcontractor">Subkontraktor</label>
                        <select id="order-subcontractor" name="subcontractor_id" required>
                            <option value="">Pilih subcon</option>
                            @foreach($subcontractors as $subcontractor)
                                <option value="{{ $subcontractor->id }}">{{ $subcontractor->name }}</option>
                            @endforeach
                        </select>
                        <label for="order-subcontractor-deadline">Deadline subcon</label>
                        <input
                            id="order-subcontractor-deadline"
                            type="date"
                            name="target_deadline"
                            value="{{ ($order->factory_deadline ?? $order->deadline)?->format('Y-m-d') }}"
                        >
                        <button class="btn btn-primary" type="submit">Alokasikan sisa order</button>
                    </form>
                @endif
                @error('subcontractor_id')
                    <span class="form-error">{{ $message }}</span>
                @enderror
            @endif
        </section>
        @endif

        @if($salesWorkflow)
            @if($order->note)
                <section class="card">
                    <h2>Catatan</h2>
                    <p>{{ $order->note }}</p>
                </section>
            @endif

        @endif
    </aside>
</div>

<section class="card order-discussion" id="order-discussion">
    <div>
        <h2>Diskusi Order</h2>
        <p class="muted">Komentar ini dapat dibaca oleh admin Pilar Bali dan Pilar Factory.</p>
    </div>

    @if(session('success'))
        <div class="message" role="status">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <ul class="error" role="alert">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @include('orders.partials.comment-list', ['order' => $order, 'returnTo' => 'show'])
    @include('orders.partials.comment-composer', ['order' => $order, 'returnTo' => 'show'])
</section>

    @if($factoryWorkflow)
        @include('admin.attendance.partials.piecework-action-dialog')
    @endif
@endif

@endsection