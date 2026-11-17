@extends('layouts.admin')

@section('title', 'Ubah Order')

@section('content')
@php($guestSourceOptions = ['Gmaps', 'Instagram', 'Website', 'Lokal'])
@php($currentGuestSource = old('guest_source', $order->guest_source ?? ''))
@php($isCustomGuestSource = $currentGuestSource === '__custom' || ($currentGuestSource !== '' && ! in_array($currentGuestSource, $guestSourceOptions, true)))
@php($customGuestSource = old('guest_source_custom', $isCustomGuestSource && $currentGuestSource !== '__custom' ? $currentGuestSource : ''))

<div class="container">

    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Penjualan</p>
            <h1>Ubah SPK {{ $order->order_id }}</h1>
            <p>Perbarui informasi pelanggan dan produk pesanan.</p>
        </div>
    </div>


    <div
        id="order-form-errors"
        class="field-error"
        role="alert"
        aria-live="assertive"
        @if(!$errors->any()) hidden @endif
    >
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>


    <form
        action="{{ route('orders.update', $order) }}"
        method="POST"
        enctype="multipart/form-data"
        data-ajax-validation
        data-validation-errors="order-form-errors"
    >

        @csrf
        @method('PUT')


        {{-- ORDER --}}

        <div class="card">

            <h2>Order Information</h2>

            <div class="row">

                <div class="form-group">

                    <label>SPK</label>

                    <input
                        type="text"
                        name="order_id"
                        value="{{ old('order_id', $order->order_id) }}"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Tamu</label>

                    <div class="guest-wrapper">
                        <input
                            type="text"
                            name="guest_picker"
                            list="guest-options"
                            value="{{ old('guest_name', $order->guest_name) }}"
                            placeholder="Pilih tamu atau ketik nama baru"
                            autocomplete="off"
                            oninput="updateGuestPhone(this)"
                            required
                        >
                        <input type="hidden" name="guest_id" value="{{ old('guest_id', $order->guest_id) }}">
                        <input
                            type="hidden"
                            name="guest_name"
                            value="{{ old('guest_name', $order->guest_name) }}"
                        >
                        @if(auth()->user()->canAccessMenu('guests'))
                            <button
                                type="button"
                                class="btn btn-success"
                                onclick="openGuestModal(this)"
                                aria-label="Tambah tamu"
                            >
                                +
                            </button>
                        @endif
                    </div>
                    <small>Pilih tamu dari daftar atau ketik nama baru untuk mendaftarkannya.</small>

                </div>


                <div class="form-group">

                    <label for="guest-source-select">Sumber Tamu</label>
                    <select id="guest-source-select" name="guest_source" onchange="updateGuestSource()" required>
                        <option value="">-- Pilih sumber --</option>
                        @foreach($guestSourceOptions as $guestSource)
                            <option value="{{ $guestSource }}" @selected($currentGuestSource === $guestSource)>
                                {{ $guestSource }}
                            </option>
                        @endforeach
                        <option value="__custom" @selected($isCustomGuestSource)>Lainnya</option>
                    </select>
                    <input
                        id="guest-source-custom"
                        type="text"
                        name="guest_source_custom"
                        value="{{ $customGuestSource }}"
                        maxlength="255"
                        placeholder="Masukkan sumber tamu"
                        @if(!$isCustomGuestSource) disabled hidden @endif
                        @if($isCustomGuestSource) required @endif
                    >
                    <small>Pilih sumber yang tersedia atau pilih Lainnya untuk menambahkan sumber baru.</small>

                </div>

                <div class="form-group">

                    <label for="order-type">Lokasi Tujuan</label>

                    <select id="order-type" name="order_type" required>
                        <option value="">-- Pilih Lokasi Tujuan --</option>
                        @foreach(['Bali', 'Luar Bali', 'Ekspor'] as $orderType)
                            <option
                                value="{{ $orderType }}"
                                @selected(old('order_type', $order->order_type) === $orderType)
                            >
                                {{ $orderType }}
                            </option>
                        @endforeach
                    </select>

                </div>


                <div class="form-group">

                    <label>No. Telpon</label>

                    <input
                        type="text"
                        name="phone"
                        id="guest-phone"
                        value="{{ old('phone', $order->phone) }}"
                    >

                </div>

                <div class="form-group">
                    <label>Alamat Tamu (opsional)</label>
                    <textarea name="guest_address" rows="2">{{ old('guest_address', $order->guest?->address) }}</textarea>
                </div>


                <div
                    class="form-group"
                    data-deadline-control
                    data-deadline-today="{{ today()->toDateString() }}"
                >
                    <label for="deadline-date">Deadline tanggal</label>
                    <input
                        id="deadline-date"
                        type="date"
                        class="date-input"
                        name="deadline"
                        value="{{ old('deadline', $order->deadline?->format('Y-m-d')) }}"
                        data-deadline-date
                    >
                    <label for="deadline-days">Atau dalam hari</label>
                    <input
                        id="deadline-days"
                        type="number"
                        min="0"
                        step="1"
                        data-deadline-days
                        aria-label="Jumlah hari sampai deadline"
                    >
                    <small>Ubah jumlah hari atau tanggal; nilai lainnya akan menyesuaikan.</small>

                </div>


                <div class="form-group">

                    <label>Status Order</label>

                    <div>{{ $order->status === 'None' ? 'New' : $order->status }}</div>

                </div>

            </div>


            <div class="form-group">

                <label>Note</label>

                <textarea
                    name="note"
                    rows="4"
                >{{ old('note', $order->note) }}</textarea>

            </div>

        </div>


        {{-- PRODUCTS --}}

        <div class="card">

            <div style="display:flex;justify-content:space-between;">

                <h2>List Produk</h2>

                <button
                    type="button"
                    class="btn btn-success"
                    onclick="addProduct()"
                >
                    + Add Product
                </button>

            </div>


            <table>

                <thead>

                    <tr>

                        <th>Produk</th>
                        <th>Category</th>
                        <th>Ukuran (P x L x T)</th>
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Warna</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th>Mockup Produk</th>
                        <th>Action</th>

                    </tr>

                </thead>


                <tbody id="product-container">

                @foreach($order->items as $index => $item)

                    @php($itemIsLocked = in_array($item->status, ['Produksi', 'Proses Kirim', 'Distribusi', 'Finishing', 'Dalam Perjalanan', 'Packing', 'Ready'], true)
                        || in_array($item->factory_status, ['Produksi', 'Proses Kirim', 'Distribusi', 'Finishing', 'Packing', 'Ready'], true)
                        || (int) $item->qty_completed > 0)
                    <tr>

                        <input
                            type="hidden"
                            name="items[{{ $index }}][id]"
                            value="{{ $item->id }}"
                        >


                        <td>

                            @if($itemIsLocked)
                                {{ $item->product }}
                                <input type="hidden" name="items[{{ $index }}][product]" value="{{ $item->product }}">
                                <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item->product_id }}">
                            @else
                            <input
                                type="text"
                                name="items[{{ $index }}][product]"
                                list="product-options"
                                autocomplete="off"
                                oninput="updateProductDetails(this)"
                                value="{{ $item->product }}"
                                required
                            >
                            <input
                                type="hidden"
                                name="items[{{ $index }}][product_id]"
                                class="catalog-product-id"
                                value="{{ $item->product_id }}"
                            >
                            @endif

                        </td>


                        <td>

                            @if($itemIsLocked)
                                {{ $item->category?->name ?? '-' }}
                                <input type="hidden" name="items[{{ $index }}][category_id]" value="{{ $item->category_id }}">
                            @else
                            <div class="category-wrapper">

                                <input
                                    type="text"
                                    class="category-search"
                                    list="category-options"
                                    autocomplete="off"
                                    placeholder="Ketik kategori"
                                    value="{{ $item->category?->name }}"
                                    oninput="updateCategoryId(this)"
                                >

                                <input
                                    type="hidden"
                                    name="items[{{ $index }}][category_id]"
                                    value="{{ $item->category_id }}"
                                >


                                @if(auth()->user()->canAccessMenu('categories'))
                                    @if(auth()->user()->canAccessMenu('categories'))
                                        <button
                                            type="button"
                                            class="btn btn-success"
                                            onclick="openCategoryModal(this)"
                                        >
                                            +
                                        </button>
                                    @endif
                                @endif

                            </div>
                            @endif

                        </td>


                        <td>
                            @if($itemIsLocked)
                                {{ $item->dimensions ?? '—' }}
                                <input type="hidden" name="items[{{ $index }}][dimensions]" value="{{ $item->dimensions }}">
                            @else
                                <input
                                    type="text"
                                    name="items[{{ $index }}][dimensions]"
                                    value="{{ old("items.$index.dimensions", $item->dimensions) }}"
                                    data-furniture-dimensions
                                    placeholder="Contoh: 10 10 10"
                                    maxlength="100"
                                    required
                                >
                            @endif
                        </td>


                        <td>

                            @if($itemIsLocked)
                                {{ $item->qty }}
                                <input type="hidden" name="items[{{ $index }}][qty]" value="{{ $item->qty }}">
                            @else
                            <input
                                type="number"
                                name="items[{{ $index }}][qty]"
                                value="{{ $item->qty }}"
                                min="1"
                                required
                                oninput="calculateTotal()"
                            >
                            @endif

                        </td>


                        <td>

                            @if($itemIsLocked)
                                Rp {{ number_format((float) $item->price, 0, ',', '.') }}
                                <input type="hidden" name="items[{{ $index }}][price]" value="{{ $item->price }}">
                            @else
                            <input
                                type="text"
                                inputmode="decimal"
                                class="price-input"
                                name="items[{{ $index }}][price]"
                                value="{{ $item->price }}"
                                required
                                oninput="formatPriceInput(this); calculateTotal()"
                            >
                            @endif

                        </td>


                        <td>

                            @if($itemIsLocked)
                                {{ $item->color ?? '-' }}
                                <input type="hidden" name="items[{{ $index }}][color]" value="{{ $item->color }}">
                            @else
                            <div class="category-wrapper">

                                <input
                                    type="text"
                                    name="items[{{ $index }}][color]"
                                    class="color-search"
                                    list="color-options"
                                    autocomplete="off"
                                    placeholder="Cari warna"
                                    value="{{ $item->color }}"
                                >

                                <button
                                    type="button"
                                    class="btn btn-success"
                                    onclick="openColorModal(this)"
                                >
                                    +
                                </button>

                            </div>
                            @endif

                        </td>


                        <td>

                            @if($itemIsLocked)
                                {{ $item->source }}
                                <input type="hidden" name="items[{{ $index }}][source]" value="{{ $item->source }}">
                            @else
                            <select
                                name="items[{{ $index }}][source]"
                            >

                                @foreach(['Stock','Order','Mix','Service'] as $source)

                                    <option
                                        value="{{ $source }}"
                                        @selected($item->source === $source)
                                    >
                                        {{ $source }}
                                    </option>

                                @endforeach

                            </select>
                            @endif

                        </td>


                        <td>

                            @if($itemIsLocked)
                                {{ $item->status }}
                                <input type="hidden" name="items[{{ $index }}][status]" value="{{ $item->status }}">
                            @else
                            <select
                                name="items[{{ $index }}][status]"
                                data-original-status="{{ $item->status }}"
                                data-status-photo-select
                            >

                                @foreach(['None','Produksi'] as $status)

                                    <option
                                        value="{{ $status }}"
                                        @selected($item->status === $status)
                                    >
                                        {{ $status }}
                                    </option>

                                @endforeach

                            </select>
                            @if($item->status === 'Distribusi')
                                <label class="product-rework-comment-field rework-comment-field" data-rework-comment hidden>
                                    Komentar pengembalian ke Produksi
                                    <textarea
                                        name="items[{{ $index }}][comment]"
                                        class="rework-comment-input"
                                        rows="2"
                                        maxlength="2000"
                                    >{{ old("items.$index.comment") }}</textarea>
                                </label>
                                @error("items.$index.comment")
                                    <span class="form-error">{{ $message }}</span>
                                @enderror
                            @endif
                            @endif

                        </td>


                        <td>

                            @if($itemIsLocked)
                                <span>Mockup terkunci setelah Produksi</span>
                            @else
                            <label for="status-image-{{ $item->id }}">Mockup produk (maksimal 10 foto)</label>
                            <input
                                id="status-image-{{ $item->id }}"
                                type="file"
                                name="items[{{ $index }}][status_images][]"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                                data-status-photo-input
                            >
                            @error("items.$index.status_images")
                                <span class="form-error">{{ $message }}</span>
                            @enderror
                            @endif

                        </td>


                        <td>

                            @if($itemIsLocked)
                                <span class="muted">Terkunci</span>
                            @else
                            <button
                                type="button"
                                class="btn btn-danger icon-button"
                                aria-label="Hapus produk"
                                title="Hapus produk"
                                onclick="removeProduct(this)"
                            >
                                <x-layout.icon name="trash" />
                            </button>
                            @endif

                        </td>

                    </tr>

                @endforeach

                </tbody>

            </table>


            <h2 style="text-align:right;">

                Total:
                Rp <span id="total-display">0</span>

            </h2>

            <div class="form-group order-payment-field">
                <label for="paid_amount">Total pembayaran / DP yang sudah diterima (Rp)</label>
                <input
                    id="paid_amount"
                    type="text"
                    inputmode="decimal"
                    class="price-input"
                    data-money-input
                    name="paid_amount"
                    value="{{ old('paid_amount', number_format((float) $order->paid_amount, 0, '.', ',')) }}"
                    autocomplete="off"
                    oninput="formatPriceInput(this)"
                >
                <small>Persentase DP dan sisa pembayaran dihitung dari total order.</small>
            </div>

        </div>


        <button
            type="submit"
            class="btn btn-primary"
        >
            Update Order
        </button>


        <a
            href="{{ route('orders.index') }}"
            class="btn btn-secondary"
        >
            Cancel
        </a>

    </form>

</div>


<datalist id="guest-options">
    @foreach($guests as $guest)
        <option value="{{ $guest->name }}"></option>
    @endforeach
</datalist>


<datalist id="product-options">
    @foreach($catalogProducts as $catalogProduct)
        <option value="{{ $catalogProduct['name'] }}"></option>
    @endforeach
    @foreach($productOptions as $productOption)
        @unless(collect($catalogProducts)->contains(fn ($catalogProduct) => mb_strtolower($catalogProduct['name']) === mb_strtolower($productOption['name'])))
            <option value="{{ $productOption['name'] }}"></option>
        @endunless
    @endforeach
</datalist>

<datalist id="category-options">
    @foreach($categories as $category)
        <option value="{{ $category->name }}"></option>
    @endforeach
</datalist>

<datalist id="color-options">
    @foreach($colors as $color)
        <option value="{{ $color }}"></option>
    @endforeach
</datalist>


{{-- MODAL --}}

<div
    id="categoryModal"
    class="modal"
>

    <div class="modal-content">

        <h2>Add New Category</h2>

        <div
            id="category-error"
            style="color:red;"
        ></div>

        <div class="form-group">

            <label>Category Name</label>

            <input
                type="text"
                id="new-category-name"
            >

        </div>


        <button
            type="button"
            class="btn btn-success"
            onclick="saveCategory()"
        >
            Save Category
        </button>


        <button
            type="button"
            class="btn btn-secondary"
            onclick="closeCategoryModal()"
        >
            Cancel
        </button>

    </div>

</div>


<div
    id="colorModal"
    class="modal"
>

    <div class="modal-content">

        <h2>Add New Color</h2>

        <div id="color-error" style="color:red;"></div>

        <div class="form-group">

            <label>Color Name</label>

            <input
                type="text"
                id="new-color-name"
            >

        </div>


        <button
            type="button"
            class="btn btn-success"
            onclick="saveColor()"
        >
            Save Color
        </button>


        <button
            type="button"
            class="btn btn-secondary"
            onclick="closeColorModal()"
        >
            Cancel
        </button>

    </div>

</div>


<div
    id="guestModal"
    class="modal"
>

    <div class="modal-content">

        <h2>Add New Guest</h2>

        <div id="guest-error" style="color:red;"></div>

        <div class="form-group">
            <label>Nama Tamu</label>
            <input type="text" id="new-guest-name" required>
        </div>

        <button type="button" class="btn btn-success" onclick="saveGuest()">
            Save Guest
        </button>

        <button type="button" class="btn btn-secondary" onclick="closeGuestModal()">
            Cancel
        </button>

    </div>

</div>


<script>

    let productIndex = {{ $order->items->count() }};

    let activeCategoryInput = null;
    let activeColorInput = null;
    let activeGuestInput = null;


    const categories = @json($categories);
    const productOptions = @json($productOptions);
    const catalogProducts = @json($catalogProducts);
    const guests = @json($guests);


    function formatPriceInput(input, trimTrailingZeros = false)
    {
        const cursorPosition = input.selectionStart ?? input.value.length;
        const textBeforeCursor = input.value.slice(0, cursorPosition);
        const normalizedValue = input.value
            .replace(/,/g, '')
            .replace(/[^\d.]/g, '');
        const decimalPosition = normalizedValue.indexOf('.');
        const integerPart = decimalPosition === -1
            ? normalizedValue
            : normalizedValue.slice(0, decimalPosition);
        let decimalPart = decimalPosition === -1
            ? ''
            : normalizedValue.slice(decimalPosition + 1).replace(/\./g, '').slice(0, 2);

        if (trimTrailingZeros) {
            decimalPart = decimalPart.replace(/0+$/, '');
        }

        const hasDecimal = decimalPosition !== -1
            && (!trimTrailingZeros || decimalPart.length > 0);
        const formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const formattedValue = hasDecimal
            ? `${formattedInteger || '0'}.${decimalPart}`
            : formattedInteger;

        input.value = formattedValue;

        if (document.activeElement !== input) {
            return;
        }

        const digitsBeforeCursor = textBeforeCursor.replace(/\D/g, '').length;
        let nextCursorPosition = 0;
        let digitsSeen = 0;

        while (nextCursorPosition < formattedValue.length && digitsSeen < digitsBeforeCursor) {
            if (/\d/.test(formattedValue[nextCursorPosition])) {
                digitsSeen++;
            }

            nextCursorPosition++;
        }

        if (textBeforeCursor.includes('.') && formattedValue[nextCursorPosition] === '.') {
            nextCursorPosition++;
        }

        input.setSelectionRange(nextCursorPosition, nextCursorPosition);
    }


    function addProduct()
    {
        const container =
            document.getElementById('product-container');

        const row =
            document.createElement('tr');


        row.innerHTML = `

            <td>
                <input
                    type="text"
                    name="items[${productIndex}][product]"
                    list="product-options"
                    autocomplete="off"
                    oninput="updateProductDetails(this)"
                    required
                >
                <input
                    type="hidden"
                    name="items[${productIndex}][product_id]"
                    class="catalog-product-id"
                >
            </td>

            <td>

                <div class="category-wrapper">

                    <input
                        type="text"
                        class="category-search"
                        list="category-options"
                        autocomplete="off"
                        placeholder="Ketik kategori"
                        oninput="updateCategoryId(this)"
                    >

                    <input
                        type="hidden"
                        name="items[${productIndex}][category_id]"
                    >

                    <button
                        type="button"
                        class="btn btn-success"
                        onclick="openCategoryModal(this)"
                    >
                        +
                    </button>

                </div>

            </td>

            <td>
                <input
                    type="text"
                    name="items[${productIndex}][dimensions]"
                    data-furniture-dimensions
                    placeholder="Contoh: 10 10 10"
                    maxlength="100"
                    required
                >
            </td>

            <td>

                <input
                    type="number"
                    name="items[${productIndex}][qty]"
                    value="1"
                    min="1"
                    required
                    oninput="calculateTotal()"
                >
            </td>

            <td>
                <input
                    type="text"
                    inputmode="decimal"
                    class="price-input"
                    name="items[${productIndex}][price]"
                    value="0"
                    required
                    data-money-input
                    oninput="formatPriceInput(this); calculateTotal()"
                >
            </td>

            <td>
                <div class="category-wrapper">
                    <input
                        type="text"
                        name="items[${productIndex}][color]"
                        class="color-search"
                        list="color-options"
                        autocomplete="off"
                        placeholder="Cari warna"
                    >
                    <button
                        type="button"
                        class="btn btn-success"
                        onclick="openColorModal(this)"
                    >
                        +
                    </button>
                </div>
            </td>

            <td>

                <select
                    name="items[${productIndex}][source]"
                >

                    <option value="Stock">Stock</option>
                    <option value="Order" selected>Order</option>
                    <option value="Mix">Mix</option>

                </select>

            </td>

            <td>
                New
                <input type="hidden" name="items[${productIndex}][status]" value="None">
            </td>

            <td><span>Unggah mockup setelah produk disimpan</span></td>

            <td>

                <button
                    type="button"
                    class="btn btn-danger icon-button"
                    aria-label="Hapus produk"
                    title="Hapus produk"
                    onclick="removeProduct(this)"
                >
                    <x-layout.icon name="trash" />
                </button>

            </td>
        `;


        container.appendChild(row);

        formatPriceInput(row.querySelector('[name*="[price]"]'), true);

        productIndex++;

        calculateTotal();
    }


    function removeProduct(button)
    {
        button.closest('tr').remove();

        calculateTotal();
    }


    function calculateTotal()
    {
        let total = 0;


        document
            .querySelectorAll('#product-container tr')
            .forEach(row => {

                const qty =
                    parseFloat(
                        row.querySelector('[name*="[qty]"]')?.value
                    ) || 0;


                const price =
                    parseFloat(
                        row.querySelector('[name*="[price]"]')?.value.replace(/,/g, '')
                    ) || 0;


                total += qty * price;

            });


        document.getElementById('total-display').innerText =
            new Intl.NumberFormat('id-ID').format(total);
    }


    function updateCategoryId(input)
    {
        const matchingCategory = categories.find(category =>
            category.name.toLocaleLowerCase() === input.value.trim().toLocaleLowerCase()
        );

        input.closest('.category-wrapper')
            .querySelector('[name$="[category_id]"]').value = matchingCategory?.id ?? '';
    }

    function updateProductDetails(input)
    {
        const row = input.closest('tr');
        const categoryInput = row.querySelector('.category-search');
        const categoryIdInput = row.querySelector('[name$="[category_id]"]');
        const productIdInput = row.querySelector('[name$="[product_id]"]');
        const dimensionsInput = row.querySelector('[name$="[dimensions]"]');
        const priceInput = row.querySelector('[name$="[price]"]');
        const colorInput = row.querySelector('[name$="[color]"]');
        const previouslySelectedCatalogId = productIdInput.value;
        const matchingCatalogProduct = catalogProducts.find(product =>
            product.name.toLocaleLowerCase() === input.value.trim().toLocaleLowerCase()
        );
        const previouslyAutofilledProduct = categoryInput.dataset.autofilledProduct;

        if (matchingCatalogProduct) {
            productIdInput.value = matchingCatalogProduct.id;
            categoryInput.value = matchingCatalogProduct.category_name ?? '';
            categoryInput.dataset.autofilledProduct = matchingCatalogProduct.name;
            categoryIdInput.value = matchingCatalogProduct.category_id ?? '';
            dimensionsInput.value = matchingCatalogProduct.dimensions ?? '';
            priceInput.value = matchingCatalogProduct.price ?? '';
            formatPriceInput(priceInput, true);
            colorInput.value = matchingCatalogProduct.colors.length === 1
                ? matchingCatalogProduct.colors[0]
                : '';
        } else {
            productIdInput.value = '';
            if (previouslySelectedCatalogId !== '') {
                categoryInput.value = '';
                categoryIdInput.value = '';
                dimensionsInput.value = '';
                priceInput.value = '';
                colorInput.value = '';
                delete categoryInput.dataset.autofilledProduct;
            }

            const matchingProduct = productOptions.find(product =>
                product.name.toLocaleLowerCase() === input.value.trim().toLocaleLowerCase()
            );
            if (matchingProduct) {
                categoryInput.value = matchingProduct.category_name ?? '';
                categoryInput.dataset.autofilledProduct = matchingProduct.name;
                categoryIdInput.value = matchingProduct.category_id ?? '';
                dimensionsInput.value = matchingProduct.dimensions ?? '';
            } else if (previouslyAutofilledProduct) {
                categoryInput.value = '';
                categoryIdInput.value = '';
                dimensionsInput.value = '';
                delete categoryInput.dataset.autofilledProduct;
            }
        }

        calculateTotal();
    }

    function setGuestSource(source)
    {
        const select = document.getElementById('guest-source-select');
        const customInput = document.getElementById('guest-source-custom');
        const knownSources = ['Gmaps', 'Instagram', 'Website', 'Lokal'];

        if (knownSources.includes(source)) {
            select.value = source;
            customInput.value = '';
        } else if (source) {
            select.value = '__custom';
            customInput.value = source;
        } else {
            select.value = '';
            customInput.value = '';
        }

        updateGuestSource();
    }

    function updateGuestSource()
    {
        const select = document.getElementById('guest-source-select');
        const customInput = document.getElementById('guest-source-custom');
        const isCustom = select.value === '__custom';

        customInput.hidden = !isCustom;
        customInput.disabled = !isCustom;
        customInput.required = isCustom;

        if (!isCustom) {
            customInput.value = '';
        }
    }

    function updateGuestPhone(input)
    {
        const normalizedValue = input.value.trim().toLocaleLowerCase();
        const matchingGuest = guests.find(guest => guest.name.toLocaleLowerCase() === normalizedValue);
        const guestWrapper = input.closest('.guest-wrapper');

        guestWrapper.querySelector('[name="guest_id"]').value = matchingGuest?.id ?? '';
        guestWrapper.querySelector('[name="guest_name"]').value = matchingGuest?.name ?? input.value.trim();
        input.closest('.row').querySelector('[name="guest_address"]').value = matchingGuest?.address ?? '';
        setGuestSource(matchingGuest?.guest_source ?? '');

        const phoneInput = input.closest('.row').querySelector('[name="phone"]');
        if (matchingGuest && !phoneInput.value) {
            phoneInput.value = matchingGuest.phones?.at(-1)?.phone ?? '';
        }
    }


    function openGuestModal(button)
    {
        activeGuestInput = button.closest('.guest-wrapper').querySelector('[name="guest_picker"]');
        document.getElementById('guestModal').style.display = 'flex';
        document.getElementById('new-guest-name').value = '';
        document.getElementById('guest-error').innerText = '';
        document.getElementById('new-guest-name').focus();
    }


    function closeGuestModal()
    {
        document.getElementById('guestModal').style.display = 'none';
    }


    async function saveGuest()
    {
        const name = document.getElementById('new-guest-name').value.trim();
        const errorBox = document.getElementById('guest-error');

        if (!name) {
            errorBox.innerText = 'Nama tamu wajib diisi.';
            return;
        }

        try {
            const response = await fetch('{{ route('guests.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ name: name })
            });

            const data = await response.json();

            if (!response.ok) {
                errorBox.innerText = data.errors?.name?.[0]
                    ?? data.errors?.phone?.[0]
                    ?? 'Gagal menambahkan tamu.';
                return;
            }

            const guest = data.guest;
            const exists = guests.some(existingGuest => existingGuest.id === guest.id);

            if (!exists) {
                guests.push(guest);
            }

            const optionExists = Array.from(document.getElementById('guest-options').options)
                .some(option => option.value.toLocaleLowerCase() === guest.name.toLocaleLowerCase());

            if (!optionExists) {
                const option = document.createElement('option');
                option.value = guest.name;
                document.getElementById('guest-options').appendChild(option);
            }

            if (activeGuestInput) {
                activeGuestInput.value = guest.name;
                activeGuestInput.closest('.guest-wrapper').querySelector('[name="guest_id"]').value = guest.id;
                activeGuestInput.closest('.guest-wrapper').querySelector('[name="guest_name"]').value = guest.name;
                activeGuestInput.closest('.row').querySelector('[name="guest_address"]').value = guest.address ?? '';
            }

            setGuestSource('');
            closeGuestModal();
        } catch (error) {
            console.error(error);
            errorBox.innerText = 'Terjadi kesalahan server.';
        }
    }


    function openCategoryModal(button)
    {
        activeCategoryInput =
            button
                .closest('.category-wrapper')
                .querySelector('.category-search');


        document.getElementById('categoryModal').style.display =
            'flex';


        document.getElementById('new-category-name').value = '';

        document.getElementById('category-error').innerText = '';

        document
            .getElementById('new-category-name')
            .focus();
    }


    function closeCategoryModal()
    {
        document.getElementById('categoryModal').style.display =
            'none';
    }


    function openColorModal(button)
    {
        activeColorInput = button
            .closest('.category-wrapper')
            .querySelector('.color-search');

        document.getElementById('colorModal').style.display = 'flex';
        document.getElementById('new-color-name').value = '';
        document.getElementById('color-error').innerText = '';
        document.getElementById('new-color-name').focus();
    }


    function closeColorModal()
    {
        document.getElementById('colorModal').style.display = 'none';
    }


    async function saveColor()
    {
        const name = document.getElementById('new-color-name').value.trim();
        const errorBox = document.getElementById('color-error');

        if (!name) {
            errorBox.innerText = 'Nama warna wajib diisi.';
            return;
        }

        try {
            const response = await fetch('{{ route('colors.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ name: name })
            });

            const data = await response.json();

            if (!response.ok) {
                errorBox.innerText = data.errors?.name?.[0]
                    ?? 'Gagal menambahkan warna.';
                return;
            }

            const option = document.createElement('option');
            option.value = data.color.name;
            document.getElementById('color-options').appendChild(option);

            if (activeColorInput) {
                activeColorInput.value = data.color.name;
            }

            closeColorModal();
        } catch (error) {
            console.error(error);
            errorBox.innerText = 'Terjadi kesalahan server.';
        }
    }


    async function saveCategory()
    {
        const name =
            document
                .getElementById('new-category-name')
                .value
                .trim();

        const errorBox = document.getElementById('category-error');

        if (!name) {
            errorBox.innerText = 'Category name wajib diisi.';
            return;
        }

        try {
            const response = await fetch('{{ route('categories.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ name: name })
            });

            const data = await response.json();

            if (!response.ok) {
                errorBox.innerText = data.errors?.name?.[0]
                    ?? 'Gagal menambahkan category.';
                return;
            }

            const category = data.category;
            categories.push(category);

            if (activeCategoryInput) {
                activeCategoryInput.value = category.name;
                updateCategoryId(activeCategoryInput);
            }
            closeCategoryModal();
        } catch (error) {
            console.error(error);
            errorBox.innerText = 'Terjadi kesalahan server.';
        }
    }


    document.querySelectorAll('[name*="[price]"]').forEach(input => {
        formatPriceInput(input, true);
    });

    const paidAmountInput = document.getElementById('paid_amount');
    formatPriceInput(paidAmountInput);

    productIndex = document.querySelectorAll('#product-container tr').length;
    calculateTotal();

</script>

@endsection