@extends('layouts.admin')

@section('title', 'Produk Orderan')

@section('content')
    @php($columnCount = $canAssignSubcontractors ? 13 : 12)

    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Operasional</p>
            <h1>Produk Orderan</h1>
            <p>Produk muncul sesuai tahap kerja unit yang sedang Anda akses.</p>
        </div>
    </div>

    <section class="order-list-panel">
        <form action="{{ route('order-products.index') }}" method="GET" class="search-form orders-filter-form">
            <input
                type="search"
                name="search"
                value="{{ $search }}"
                placeholder="Cari produk atau SPK"
                aria-label="Cari produk atau SPK"
            >
            @if(count($statuses) > 1)
                <select name="status" aria-label="Filter status produk">
                    <option value="">Semua Status</option>
                    @foreach($statusOptions as $status)
                        <option value="{{ $status }}" @selected($statusFilter === $status)>
                            {{ $factoryUser && $status === 'None' ? 'New' : ($status === 'Produksi' && ! $factoryUser ? 'Produksi (QC Stock)' : $status) }}
                        </option>
                    @endforeach
                </select>
            @endif
            <button type="submit" class="btn primary">Cari</button>
        </form>

        <div class="table-wrapper orders-table">
            <table>
                <thead>
                    <tr>
                        <th>SPK</th>
                        <th>Produk</th>
                        <th>Kategori</th>
                        @if($canAssignSubcontractors)
                            <th>Subkontraktor</th>
                        @endif
                        <th>Jumlah Order</th>
                        <th>Sedang Dikerjakan</th>
                        <th>Belum Dikerjakan</th>
                        <th>Warna</th>
                        <th>Status Produk</th>
                        <th>Target Kirim</th>
                        <th>Sisa Hari</th>
                        <th>Status Order</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php($factoryCompleted = $factoryUser && $item->factory_status === 'Packing')
                        @php($stockFactoryReady = $item->source !== 'Stock'
                            || ((int) $item->quality_unchecked_quantity === 0
                                && (int) $item->service_quantity === 0
                                && (int) $item->packed_quantity === 0))
                        <tr @class(['factory-complete-row' => $factoryCompleted])>
                            @php($itemProductStatuses = $factoryUser
                                ? ($item->factory_status === 'Distribusi'
                                    ? ['Distribusi', 'Produksi']
                                    : ($item->factory_status === 'Proses Kirim'
                                        ? ['Produksi', 'Proses Kirim', 'Distribusi']
                                        : ($item->factory_status === 'None'
                                            ? ['None', 'Produksi', 'Proses Kirim']
                                            : ['Produksi', 'Proses Kirim'])))
                                : $productStatuses)
                            <td>
                                @if(auth()->user()->canAccessMenu('orders'))
                                    <a href="{{ route('orders.show', $item->order) }}">{{ $item->order->order_id }}</a>
                                @else
                                    {{ $item->order->order_id }}
                                @endif
                            </td>
                            <td>{{ $item->product }}</td>
                            <td>{{ $item->category?->name ?? '-' }}</td>
                            @if($canAssignSubcontractors)
                                <td>
                                    @forelse($item->activeSubcontractorAssignments as $assignment)
                                        <div>
                                            {{ $assignment->subcontractor->name }}
                                            <small>{{ $assignment->assigned_quantity }} unit · {{ $assignment->status }}</small>
                                        </div>
                                    @empty
                                        <span class="muted">Belum dialokasikan</span>
                                    @endforelse

                                    @php($activePieceworkAssignments = $item->pieceworkAssignments->filter(fn ($assignment) => $assignment->completed_at === null && $assignment->cancelled_at === null))
                                    @forelse($activePieceworkAssignments as $assignment)
                                        <div>
                                            <strong>{{ $assignment->employee?->full_name ?? 'Karyawan tidak tersedia' }}</strong>
                                            <small>{{ $assignment->target_quantity }} unit · {{ $assignment->produced_quantity ?? 0 }}/{{ $assignment->target_quantity }}</small>
                                        </div>
                                        <form action="{{ route('piecework-assignments.update', $assignment) }}" method="POST" class="compact-form">
                                            @csrf
                                            @method('PATCH')
                                            <label class="sr-only" for="piecework-employee-{{ $assignment->id }}">Pekerja borongan</label>
                                            <select id="piecework-employee-{{ $assignment->id }}" name="employee_id" required>
                                                <option value="">Pilih karyawan</option>
                                                @foreach($factoryEmployees as $factoryEmployee)
                                                    <option value="{{ $factoryEmployee->id }}" @selected((string) old('employee_id', $assignment->employee_id) === (string) $factoryEmployee->id)>
                                                        {{ $factoryEmployee->full_name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <label class="sr-only" for="piecework-quantity-{{ $assignment->id }}">Jumlah target</label>
                                            <input
                                                id="piecework-quantity-{{ $assignment->id }}"
                                                type="number"
                                                name="target_quantity"
                                                min="{{ max((int) $assignment->produced_quantity + (int) $assignment->completed_quantity + (int) $assignment->cancelled_quantity, 1) }}"
                                                max="{{ max(1, $item->factory_qty_remaining + $assignment->target_quantity) }}"
                                                value="{{ old('target_quantity', $assignment->target_quantity) }}"
                                                required
                                            >
                                            <label class="sr-only" for="piecework-deadline-{{ $assignment->id }}">Deadline borongan</label>
                                            <input
                                                id="piecework-deadline-{{ $assignment->id }}"
                                                type="date"
                                                name="target_deadline"
                                                value="{{ old('target_deadline', $assignment->target_deadline?->format('Y-m-d')) }}"
                                            >
                                            <button type="submit" class="btn">Alihkan</button>
                                        </form>
                                    @empty
                                        @if($item->activeSubcontractorAssignments->isEmpty()
                                            && $item->factory_status === 'Produksi'
                                            && filled($item->factory_drawing_image)
                                            && $stockFactoryReady
                                            && $item->factory_qty_remaining > 0)
                                            <form action="{{ route('order-items.piecework-assignments.store', $item) }}" method="POST" class="compact-form">
                                                @csrf
                                                <label class="sr-only" for="piecework-employee-{{ $item->id }}">Karyawan borongan untuk {{ $item->product }}</label>
                                                <select id="piecework-employee-{{ $item->id }}" name="employee_id" required>
                                                    <option value="">Pilih karyawan</option>
                                                    @foreach($factoryEmployees as $factoryEmployee)
                                                        <option value="{{ $factoryEmployee->id }}">{{ $factoryEmployee->full_name }}</option>
                                                    @endforeach
                                                </select>
                                                <label class="sr-only" for="piecework-quantity-{{ $item->id }}">Jumlah target</label>
                                                <input
                                                    id="piecework-quantity-{{ $item->id }}"
                                                    type="number"
                                                    name="target_quantity"
                                                    min="1"
                                                    max="{{ $item->factory_qty_remaining }}"
                                                    value="{{ old('target_quantity', $item->factory_qty_remaining) }}"
                                                    required
                                                >
                                                <label class="sr-only" for="piecework-deadline-{{ $item->id }}">Deadline borongan</label>
                                                <input
                                                    id="piecework-deadline-{{ $item->id }}"
                                                    type="date"
                                                    name="target_deadline"
                                                    min="{{ now(auth()->user()->timezone())->toDateString() }}"
                                                    value="{{ old('target_deadline', $item->order->deadline?->format('Y-m-d') ?: now(auth()->user()->timezone())->toDateString()) }}"
                                                    required
                                                >
                                                <button type="submit" class="btn">Tugaskan</button>
                                            </form>
                                        @endif
                                    @endforelse

                                    @if($item->factory_status === 'Produksi'
                                        && filled($item->factory_drawing_image)
                                        && $stockFactoryReady
                                        && $item->factory_quantity > $item->qty_completed)
                                        <form action="{{ route('order-products.subcontractor-assignments.store', $item) }}" method="POST" class="compact-form">
                                            @csrf
                                            <label class="sr-only" for="subcontractor-{{ $item->id }}">Subkontraktor untuk {{ $item->product }}</label>
                                            <select id="subcontractor-{{ $item->id }}" name="subcontractor_id" required>
                                                <option value="">Pilih subcon</option>
                                                @foreach($subcontractors as $subcontractor)
                                                    @if($subcontractor->is_active)
                                                        <option value="{{ $subcontractor->id }}" @selected((string) old('subcontractor_id') === (string) $subcontractor->id)>
                                                            {{ $subcontractor->name }}
                                                        </option>
                                                    @endif
                                                @endforeach
                                            </select>
                                            <label class="sr-only" for="subcontractor-quantity-{{ $item->id }}">Jumlah untuk subcon</label>
                                            <input
                                                id="subcontractor-quantity-{{ $item->id }}"
                                                type="number"
                                                name="quantity"
                                                min="1"
                                                max="{{ $item->factory_quantity - $item->qty_completed }}"
                                                value="{{ old('quantity', $item->factory_quantity - $item->qty_completed) }}"
                                                required
                                            >
                                            <label class="sr-only" for="subcontractor-deadline-{{ $item->id }}">Deadline pekerjaan subcon</label>
                                            <input
                                                id="subcontractor-deadline-{{ $item->id }}"
                                                type="date"
                                                name="target_deadline"
                                                value="{{ old('target_deadline', $item->order->deadline?->format('Y-m-d')) }}"
                                            >
                                            <button type="submit" class="btn">Alokasikan</button>
                                        </form>
                                    @endif
                                    @error('subcontractor_id')
                                        <span class="form-error">{{ $message }}</span>
                                    @enderror
                                    @error('quantity')
                                        <span class="form-error">{{ $message }}</span>
                                    @enderror
                                </td>
                            @endif
                            <td>{{ $factoryUser ? $item->factory_quantity : $item->qty }}</td>
                            <td>
                                @if($factoryCompleted)
                                    {{ $item->qty_completed }}
                                @elseif($factoryUser || $item->source === 'Stock')
                                    {{ $item->qty_completed }}
                                @else
                                    <label class="sr-only" for="qty-completed-{{ $item->id }}">Sudah dikerjakan untuk {{ $item->product }}</label>
                                    <input
                                        class="product-progress-input"
                                        id="qty-completed-{{ $item->id }}"
                                        type="number"
                                        name="items[{{ $item->id }}][qty_completed]"
                                        form="update-order-product-{{ $item->id }}"
                                        min="0"
                                        max="{{ $factoryUser ? $item->factory_quantity : $item->qty }}"
                                        value="{{ old("items.{$item->id}.qty_completed", $item->qty_completed) }}"
                                        required
                                    >
                                    @error("items.{$item->id}.qty_completed")
                                        <span class="form-error">{{ $message }}</span>
                                    @enderror
                                @endif
                            </td>
                            <td>{{ $factoryUser ? $item->factory_qty_remaining : $item->qty_remaining }}</td>
                            <td>{{ $item->color ?? '-' }}</td>
                            <td>
                                @if($factoryUser)
                                    <strong>{{ $item->factory_status === 'None' || ($item->status === 'Produksi' && $item->source !== 'Stock' && blank($item->factory_drawing_image)) ? 'New' : $item->factory_status }}</strong>
                                    @if($item->factory_status === 'Produksi' && filled($item->factory_drawing_image))
                                        <p>Produksi tersisa: {{ $item->factory_qty_remaining }} unit</p>
                                        <p>Finishing tersedia: {{ $item->finishing_quantity_available }} unit</p>
                                    @endif
                                    @if(in_array($item->status, ['Distribusi', 'Produksi'], true)
                                        && ($item->source !== 'Stock' || (int) $item->quality_damaged_quantity > 0)
                                        && blank($item->factory_drawing_image))
                                        <p class="muted">Menunggu gambar kerja PF sebelum produksi dimulai.</p>
                                    @endif
                                    @if((int) $item->quality_damaged_quantity > 0)
                                        <span class="stagnant-product-warning" role="alert">
                                            {{ $item->source === 'Stock' ? 'Produk Stock rusak menunggu produksi ulang' : 'Produk rusak menunggu finishing ulang' }}: {{ $item->quality_damaged_quantity }} unit.
                                        </span>
                                    @endif
                                    @if(in_array($item->status, ['Distribusi', 'Produksi'], true)
                                        && ($item->source !== 'Stock' || (int) $item->quality_damaged_quantity > 0))
                                        @if($item->factory_drawing_image)
                                            <a href="{{ Storage::disk('public')->url($item->factory_drawing_image) }}" target="_blank" rel="noopener">Lihat gambar kerja</a>
                                        @elseif($item->factory_status === 'Produksi')
                                            <span class="muted">Belum ada gambar kerja; produk belum dapat ditugaskan.</span>
                                        @endif
                                        <form action="{{ route('order-products.factory-drawing.update', $item) }}" method="POST" enctype="multipart/form-data" class="compact-form">
                                            @csrf
                                            @method('PATCH')
                                            <label for="factory-drawing-{{ $item->id }}">
                                                {{ $item->factory_drawing_image ? 'Ganti gambar kerja' : 'Unggah gambar kerja tukang' }}
                                            </label>
                                            <input
                                                id="factory-drawing-{{ $item->id }}"
                                                type="file"
                                                name="factory_drawing_image"
                                                accept="image/jpeg,image/png,image/webp"
                                                required
                                            >
                                            <button type="submit" class="btn primary">Simpan gambar kerja</button>
                                        </form>
                                    @elseif($item->factory_status === 'Finishing')
                                        <p>Finishing tersedia: {{ $item->finishing_quantity_available }} unit</p>
                                    @elseif($item->factory_status === 'Packing')
                                        <p>Dalam perjalanan / menunggu pemeriksaan PB: {{ $item->packed_quantity }} unit</p>
                                    @endif
                                @elseif($item->source === 'Stock'
                                    && (in_array($item->status, ['Distribusi', 'Dalam Perjalanan'], true)
                                        || ($item->status === 'Produksi' && $item->quality_unchecked_quantity > 0)
                                        || $item->service_quantity > 0))
                                    @php($stockInspectionQuantity = $item->status === 'Dalam Perjalanan'
                                        ? (int) $item->packed_quantity
                                        : $item->quality_unchecked_quantity)
                                    @if($stockInspectionQuantity > 0)
                                        <div class="stock-quality-actions">
                                            @foreach(['Bagus', 'Rusak', 'Kurang'] as $qualityResult)
                                                <button
                                                    type="button"
                                                    class="{{ $qualityResult === 'Bagus' ? 'btn primary' : 'btn' }}"
                                                    data-stock-quality-result="{{ $qualityResult }}"
                                                    data-stock-quality-url="{{ route('order-products.quality.store', $item) }}"
                                                    data-stock-quality-max="{{ $stockInspectionQuantity }}"
                                                    data-stock-quality-product="{{ $item->product }}"
                                                >
                                                    {{ $qualityResult }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                @elseif($item->status === 'Dalam Perjalanan')
                                    <p>Belum diperiksa: {{ $item->quality_unchecked_quantity }} / {{ $item->qty }} unit</p>
                                    @if((int) $item->packed_quantity > 0 || $item->quality_shortage_available > 0)
                                        <form action="{{ route('order-products.quality.store', $item) }}" method="POST" class="compact-form">
                                            @csrf
                                            <label for="quality-result-{{ $item->id }}">Hasil pemeriksaan</label>
                                            <select id="quality-result-{{ $item->id }}" name="result" required>
                                                <option value="Bagus">Bagus</option>
                                                <option value="Rusak">Rusak</option>
                                                <option value="Kurang">Kurang (service Bali)</option>
                                            </select>
                                            <label for="quality-quantity-{{ $item->id }}">Jumlah</label>
                                            <input id="quality-quantity-{{ $item->id }}" type="number" name="quantity" min="1" max="{{ $item->quality_unchecked_quantity }}" required>
                                            <label for="quality-note-{{ $item->id }}">Catatan</label>
                                            <textarea id="quality-note-{{ $item->id }}" name="note" rows="2" maxlength="1000"></textarea>
                                            <button type="submit" class="btn primary">Simpan hasil QC</button>
                                        </form>
                                    @endif
                                @else
                                    <select
                                        class="product-status-select"
                                        name="items[{{ $item->id }}][status]"
                                        form="update-order-product-{{ $item->id }}"
                                        aria-label="Status produk {{ $item->product }}"
                                        data-original-status="{{ $factoryUser ? $item->factory_status : $item->status }}"
                                        data-status-photo-select
                                        required
                                    >
                                        @foreach($itemProductStatuses as $productStatus)
                                            <option
                                                value="{{ $productStatus }}"
                                                @selected(old("items.{$item->id}.status", $factoryUser ? $item->factory_status : $item->status) === $productStatus)
                                            >
                                                {{ $productStatus }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error("items.{$item->id}.status")
                                        <span class="form-error">{{ $message }}</span>
                                    @enderror
                                @endif
                                @unless($factoryUser || $item->source === 'Stock')
                                    <label for="status-image-{{ $item->id }}">Mockup produk (maksimal 10 foto)</label>
                                    <input
                                        id="status-image-{{ $item->id }}"
                                        type="file"
                                        name="items[{{ $item->id }}][status_images][]"
                                        form="update-order-product-{{ $item->id }}"
                                        accept="image/jpeg,image/png,image/webp"
                                        multiple
                                        data-status-photo-input
                                    >
                                    @error("items.{$item->id}.status_images")
                                        <span class="form-error">{{ $message }}</span>
                                    @enderror
                                @endunless
                            </td>
                            <td>{{ $item->order->deadline?->format('d/m/Y') ?? '-' }}</td>
                            <td>
                                @if($item->days_remaining === null)
                                    -
                                @elseif($item->days_remaining < 0)
                                    Terlambat {{ abs($item->days_remaining) }} hari
                                @elseif($item->days_remaining === 0)
                                    Hari ini
                                @else
                                    {{ $item->days_remaining }} hari lagi
                                @endif
                            </td>
                            <td>
                                {{ $item->order->status === 'None' ? 'New' : $item->order->status }}
                                @if($item->order->is_urgent)
                                    <span class="urgent">URGENT</span>
                                @endif
                            </td>
                            <td>
                                @if(! $factoryUser && $item->source === 'Stock')
                                    @foreach($item->services as $service)
                                        <form
                                            action="{{ route('order-item-services.result.store', $service) }}"
                                            method="POST"
                                            class="compact-form"
                                        >
                                            @csrf
                                            <strong>Service {{ $service->severity }} · {{ $service->quantity }} unit</strong>
                                            <label for="service-quantity-{{ $service->id }}">Jumlah diproses</label>
                                            <div class="stock-quality-quantity-control">
                                                <input
                                                    id="service-quantity-{{ $service->id }}"
                                                    type="number"
                                                    name="quantity"
                                                    min="1"
                                                    max="{{ $service->quantity }}"
                                                    value="{{ $service->quantity }}"
                                                    required
                                                >
                                                <button type="button" class="btn" data-service-fill-all>Semua</button>
                                            </div>
                                            <div class="stock-quality-actions">
                                                <button class="btn primary" type="submit" name="result" value="Bagus">Bagus</button>
                                                <button class="btn" type="submit" name="result" value="Rusak">Rusak · kirim PF</button>
                                            </div>
                                        </form>
                                    @endforeach
                                @endif
                                @unless($factoryUser || $item->status === 'Dalam Perjalanan' || $item->source === 'Stock')
                                    <form
                                        id="update-order-product-{{ $item->id }}"
                                        action="{{ route('order-products.update', $item) }}"
                                        method="POST"
                                        enctype="multipart/form-data"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn primary product-save-button">Save</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                        @if($stagnantItemId === $item->id)
                            <tr>
                                <td colspan="{{ $columnCount }}">
                                    <span class="stagnant-product-warning" role="alert" data-stagnant-product-warning>
                                        Peringatan: progres produk ini tidak berubah selama 7 hari.
                                    </span>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $columnCount }}">Tidak ada produk pada tahap ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @unless($factoryUser)
            <dialog
                class="stock-quality-dialog"
                data-stock-quality-dialog
                aria-labelledby="stock-quality-dialog-title"
                aria-describedby="stock-quality-dialog-description"
            >
                <form method="POST" class="stock-quality-form" data-stock-quality-form>
                    @csrf
                    <h2 id="stock-quality-dialog-title" data-stock-quality-title>Pemeriksaan Stock</h2>
                    <p id="stock-quality-dialog-description" data-stock-quality-description></p>
                    <input type="hidden" name="result" data-stock-quality-result-value>
                    <div data-stock-quality-severity-field hidden>
                        <label for="stock-quality-dialog-severity">Tingkat kerusakan</label>
                        <select
                            id="stock-quality-dialog-severity"
                            name="severity"
                            data-stock-quality-severity
                        >
                            <option value="">Pilih tingkat kerusakan</option>
                            <option value="Ringan">Ringan · Service di PB</option>
                            <option value="Parah">Parah · Kirim ke PF/Produksi</option>
                        </select>
                    </div>
                    <label for="stock-quality-dialog-quantity">Jumlah yang diperiksa</label>
                    <div class="stock-quality-quantity-control">
                        <input
                            id="stock-quality-dialog-quantity"
                            type="number"
                            name="quantity"
                            min="1"
                            required
                            data-stock-quality-quantity
                        >
                        <button type="button" class="btn" data-stock-quality-fill-all>Semua</button>
                    </div>
                    <div class="stock-quality-dialog-actions">
                        <button type="button" class="btn" data-stock-quality-close>Batal</button>
                        <button type="submit" class="btn primary" data-stock-quality-submit>Simpan hasil QC</button>
                    </div>
                </form>
            </dialog>
        @endunless

        {{ $items->links() }}
    </section>
@endsection
