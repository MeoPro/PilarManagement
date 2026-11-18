@extends('layouts.admin')

@section('title', 'Detail Order')

@section('content')
<div class="page-heading">
    <div>
        <p class="page-eyebrow">Penjualan / Detail SPK</p>
        <h1>SPK {{ $order->order_id }}</h1>
        <p>Detail pesanan, tenggat, dan progres produksi.</p>
    </div>
    <a class="btn" href="{{ route('orders.index') }}">Kembali ke Order</a>
</div>


<div class="order-detail-layout">
    <section class="card order-detail-main">
        <div class="order-detail-heading">
            <h2>Produk</h2>
            <span class="status-badge">{{ ($factoryWorkflow ? $order->is_factory_urgent : $order->is_urgent) ? 'URGENT' : ($order->status === 'None' ? 'New' : $order->status) }}</span>
        </div>

        <label for="order-product-status-filter">Filter produk</label>
        <select id="order-product-status-filter" data-order-product-status-filter>
            <option value="">Semua produk ({{ $order->items->count() }})</option>
            @foreach($productStatusCounts as $productStatus => $quantity)
                <option value="{{ $productStatus }}">{{ $productStatus }} ({{ $quantity }} unit)</option>
            @endforeach
        </select>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Kategori</th>
                        <th>Ukuran (P x L x T)</th>
                        <th>Jumlah</th>
                        @if($salesWorkflow)
                            <th>Harga</th>
                            <th>Subtotal</th>
                        @endif
                        <th>Warna</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th>Mockup dan Gambar Kerja PF</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                        @php($completedQuantity = $item->completed_quantity)
                        @php($productionQuantity = $item->production_quantity)
                        @php($serviceQuantity = $item->service_quantity)
                        <tr
                            data-order-product-row
                            data-status-selesai="{{ $completedQuantity > 0 ? '1' : '0' }}"
                            data-status-produksi="{{ $productionQuantity > 0 ? '1' : '0' }}"
                            data-status-service="{{ $serviceQuantity > 0 ? '1' : '0' }}"
                        >
                            <td>
                                {{ $item->product }}
                                @if($item->sku)
                                    <small>SKU: {{ $item->sku }}</small>
                                @endif
                            </td>
                            <td>{{ $item->category?->name ?? '-' }}</td>
                            <td>{{ $item->dimensions ?? '—' }}</td>
                            <td>
                                {{ $item->qty }}
                                @if($factoryWorkflow)
                                    <small>Factory {{ $item->qty_completed }}/{{ $item->factory_quantity }}</small>
                                    @if((int) $item->active_subcontracted_quantity > 0)
                                        <small>Subcon {{ $item->active_subcontractor_completed_quantity }}/{{ $item->active_subcontracted_quantity }} selesai</small>
                                    @endif
                                @endif
                            </td>
                            @if($salesWorkflow)
                                <td>Rp {{ number_format($item->price, 0, '.', ',') }}</td>
                                <td>Rp {{ number_format($item->subtotal, 0, '.', ',') }}</td>
                            @endif
                            <td>{{ $item->color ?? '-' }}</td>
                            <td>{{ $item->source }}</td>
                            <td>
                                @if($factoryWorkflow)
                                    <strong>{{ $item->factory_status === 'None' || ($item->status === 'Produksi' && $item->source !== 'Stock' && blank($item->factory_drawing_image)) ? 'New' : ($item->factory_status === 'Ready' ? 'Selesai' : $item->factory_status) }}</strong>
                                    <small>Produksi {{ $item->qty_completed }}/{{ $item->factory_quantity }}</small>
                                    @if((int) $item->quality_damaged_quantity > 0)
                                        <span class="stagnant-product-warning" role="alert">
                                            {{ $item->source === 'Stock' ? 'Stock rusak menunggu produksi ulang' : 'Rusak menunggu finishing ulang' }}: {{ $item->quality_damaged_quantity }} unit.
                                        </span>
                                    @endif
                                    @if((int) $item->packed_quantity > 0)
                                        <small>Dalam perjalanan {{ $item->packed_quantity }} unit</small>
                                    @endif
                                @else
                                    {{ $item->status === 'Ready' ? 'Selesai' : $item->status }}
                                @endif
                                <small>{{ $completedQuantity }} Selesai · {{ $productionQuantity }} Produksi · {{ $serviceQuantity }} Service</small>
                                @if($item->services->isNotEmpty())
                                    <details>
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
                            </td>
                            <td>
                                <div class="order-mockup-gallery">
                                    @forelse($item->status_evidence_images as $statusImage)
                                        <a href="{{ asset('storage/'.$statusImage) }}" target="_blank" rel="noopener noreferrer">
                                            <img
                                                class="product-catalog-photo product-catalog-photo-large"
                                                src="{{ asset('storage/'.$statusImage) }}"
                                                alt="Mockup {{ $item->product }}"
                                            >
                                        </a>
                                    @empty
                                        -
                                    @endforelse
                                    @if($factoryWorkflow && $item->factory_drawing_image)
                                        <a href="{{ asset('storage/'.$item->factory_drawing_image) }}" target="_blank" rel="noopener noreferrer">
                                            <img
                                                class="product-catalog-photo product-catalog-photo-large"
                                                src="{{ asset('storage/'.$item->factory_drawing_image) }}"
                                                alt="Gambar kerja {{ $item->product }}"
                                            >
                                        </a>
                                    @endif
                                </div>
                                @if($canAssignPiecework
                                    && in_array($item->status, ['Distribusi', 'Produksi'], true)
                                    && ($item->source !== 'Stock' || (int) $item->quality_damaged_quantity > 0)
                                    && blank($item->factory_drawing_image)
                                    && $order->status !== 'Cancel')
                                    <form
                                        action="{{ route('order-products.factory-drawing.update', $item) }}"
                                        method="POST"
                                        enctype="multipart/form-data"
                                        class="compact-form"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <label for="factory-drawing-{{ $item->id }}">Unggah gambar kerja tukang PF</label>
                                        <input
                                            id="factory-drawing-{{ $item->id }}"
                                            type="file"
                                            name="factory_drawing_image"
                                            accept="image/jpeg,image/png,image/webp"
                                            required
                                        >
                                        <button type="submit" class="btn primary">Simpan gambar kerja</button>
                                    </form>
                                @endif
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
            <section class="subcontractor-breakdown">
                <h2>Rincian Pekerjaan Factory</h2>
                @if($canAssignPiecework)
                    @foreach($order->items as $item)
                        @php($stockFactoryReady = $item->source !== 'Stock'
                            || ((int) $item->quality_unchecked_quantity === 0
                                && (int) $item->service_quantity === 0
                                && (int) $item->packed_quantity === 0))
                        @php($reservedPieceworkQuantity = $item->pieceworkAssignments->whereNull('completed_at')->whereNull('cancelled_at')->sum(fn ($assignment) => max($assignment->target_quantity - $assignment->completed_quantity - $assignment->cancelled_quantity - (int) $assignment->produced_quantity, 0)))
                        @php($assignablePieceworkQuantity = max($item->factory_qty_remaining - $reservedPieceworkQuantity, 0))
                        @if($item->activeSubcontractorAssignments->isNotEmpty())
                            <p class="muted">Produk ini sudah dialokasikan ke Subcon dan tidak dapat ditugaskan ke borongan PF.</p>
                        @elseif($item->source === 'Stock' && ! $stockFactoryReady)
                            <p class="muted">Selesaikan pemeriksaan PB dan Service untuk seluruh Stock sebelum penugasan PF.</p>
                        @elseif($item->factory_status !== 'Produksi' || blank($item->factory_drawing_image))
                            <p class="muted">Penugasan borongan tersedia setelah produk berstatus Produksi dan gambar kerja PF diunggah.</p>
                        @elseif($stockFactoryReady && $assignablePieceworkQuantity > 0)
                            <form action="{{ route('order-items.piecework-assignments.store', $item) }}" method="POST" class="production-status-form piecework-assignment-form">
                                @csrf
                                <h3>Tambah penugasan: {{ $item->product }}</h3>
                                <label for="piecework-employee-{{ $item->id }}">Karyawan Borongan</label>
                                <select id="piecework-employee-{{ $item->id }}" name="employee_id" required>
                                    <option value="">Pilih karyawan</option>
                                    @foreach($factoryEmployees as $factoryEmployee)
                                        <option value="{{ $factoryEmployee->id }}">{{ $factoryEmployee->full_name }}</option>
                                    @endforeach
                                </select>
                                <label for="piecework-quantity-{{ $item->id }}">Jumlah target (sisa {{ $assignablePieceworkQuantity }})</label>
                                <input
                                    id="piecework-quantity-{{ $item->id }}"
                                    type="number"
                                    name="target_quantity"
                                    min="1"
                                    max="{{ $assignablePieceworkQuantity }}"
                                    required
                                >
                                <label for="piecework-deadline-{{ $item->id }}">Deadline</label>
                                <input
                                    id="piecework-deadline-{{ $item->id }}"
                                    type="date"
                                    name="target_deadline"
                                    min="{{ now(auth()->user()->timezone())->toDateString() }}"
                                    value="{{ ($order->factory_deadline ?? $order->deadline)?->greaterThanOrEqualTo(today(auth()->user()->timezone())) ? ($order->factory_deadline ?? $order->deadline)->format('Y-m-d') : now(auth()->user()->timezone())->toDateString() }}"
                                    required
                                >
                                <button class="btn btn-primary" type="submit">Tambah penugasan</button>
                            </form>
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
                                                            value="{{ $assignment->id === (int) old('assignment_form_id') ? old('unit_price', $assignment->unit_price) : $assignment->unit_price }}"
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
        @endif

        @if($salesWorkflow)
            <h2 class="order-detail-total">Total: Rp {{ number_format($order->total_order, 0, '.', ',') }}</h2>
            <section class="order-payment-summary">
                <h3>Pembayaran</h3>
                <p><strong>DP / Dibayar:</strong> {{ number_format($order->paid_percentage, 1, ',', '.') }}% · Rp {{ number_format($order->paid_amount, 0, '.', ',') }}</p>
                <p><strong>Sisa pembayaran:</strong> Rp {{ number_format($order->amount_remaining, 0, '.', ',') }}</p>
                <p><strong>Status:</strong> {{ $order->is_debt ? 'Hutang' : ($order->is_paid ? 'Lunas' : 'Belum lunas') }}</p>
            </section>
        @endif
    </section>

    <aside class="order-detail-sidebar">
        <section class="card">
            <h2>Informasi Order</h2>
            <p><strong>SPK:</strong> {{ $order->order_id }}</p>
            @if($factoryWorkflow)
                <p><strong>Target PF:</strong> {{ $order->factory_deadline?->format('d M Y') ?? 'Belum dimulai' }}</p>
            @endif
            <p><strong>Target Kirim:</strong> {{ $order->deadline?->format('d M Y') ?? '-' }}</p>
            <p><strong>Status:</strong> {{ ($factoryWorkflow ? $order->is_factory_urgent : $order->is_urgent) ? 'URGENT' : ($order->status === 'None' ? 'New' : $order->status) }}</p>
            <p><strong>Terakhir diubah:</strong> {{ $order->last_edit?->format('d M Y H:i') ?? '-' }} WITA</p>

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
                <form action="{{ route('orders.subcontractor-assignments.store', $order) }}" method="POST" class="production-status-form">
                    @csrf
                    <h3>Alokasikan seluruh sisa order</h3>
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
        </section>

        @if($salesWorkflow)
            <section class="card">
                <h2>Informasi Tamu</h2>
                <p><strong>Nama:</strong> {{ $order->guest_name }}</p>
                <p><strong>Sumber:</strong> {{ $order->guest_source ?? '-' }}</p>
                <p><strong>Lokasi Tujuan:</strong> {{ $order->order_type ?? '-' }}</p>
                <p><strong>Telepon:</strong> {{ $order->phone ?? '-' }}</p>
                <p><strong>Alamat:</strong> {{ $order->guest?->address ?? '-' }}</p>
            </section>

            @if($order->note)
                <section class="card">
                    <h2>Catatan</h2>
                    <p>{{ $order->note }}</p>
                </section>
            @endif

            <a class="btn btn-primary" href="{{ route('orders.edit', $order) }}">Edit Order</a>
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

    <div class="order-comment-list" data-order-comment-list>
        @forelse($order->comments as $comment)
            @include('orders.partials.comment-thread', ['comment' => $comment, 'order' => $order])
        @empty
            <p class="muted" data-order-comment-empty>Belum ada komentar pada order ini.</p>
        @endforelse
    </div>

    <form action="{{ route('orders.comments.store', $order) }}" method="POST" class="order-comment-new-form" data-order-comment-form>
        @csrf
        @if(count(auth()->user()->accessibleUnits()) > 1)
            <label for="comment-business-unit">Kirim sebagai</label>
            <select id="comment-business-unit" name="business_unit" required>
                @foreach(auth()->user()->accessibleUnits() as $unit)
                    <option value="{{ $unit }}" @selected(empty(old('parent_id')) && old('business_unit') === $unit)>
                        {{ $unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}
                    </option>
                @endforeach
            </select>
        @endif
        <label for="new-order-comment">Tulis komentar</label>
        <textarea id="new-order-comment" name="body" rows="3" maxlength="5000" placeholder="Tulis komentar untuk unit lain..." required>{{ empty(old('parent_id')) ? old('body') : '' }}</textarea>
        <p class="message" role="status" data-comment-feedback hidden></p>
        <p class="error" role="alert" data-comment-error hidden></p>
        <div class="order-comment-form-actions">
            <button class="btn btn-primary" type="submit">Kirim Komentar</button>
        </div>
    </form>
</section>

    @if($factoryWorkflow)
        @include('admin.attendance.partials.piecework-action-dialog')
    @endif

@endsection