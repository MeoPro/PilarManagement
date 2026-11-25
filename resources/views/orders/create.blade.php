@extends('layouts.admin')

@section('title', 'Buat Order')

@section('content')
@php($guestSourceOptions = ['Gmaps', 'Instagram', 'Website', 'Lokal'])
@php($currentGuestSource = old('guest_source', ''))
@php($isCustomGuestSource = $currentGuestSource === '__custom' || ($currentGuestSource !== '' && ! in_array($currentGuestSource, $guestSourceOptions, true)))
@php($customGuestSource = old('guest_source_custom', $isCustomGuestSource && $currentGuestSource !== '__custom' ? $currentGuestSource : ''))

<div class="container">

    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Penjualan</p>
            <h1>Buat Order</h1>
            <p>Lengkapi informasi pelanggan dan produk pesanan.</p>
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
        class="order-create-form"
        action="{{ route('orders.store') }}"
        method="POST"
        enctype="multipart/form-data"
        data-ajax-validation
        data-validation-errors="order-form-errors"
        data-check-order-id-url="{{ route('orders.check-id') }}"
    >

        @csrf


        {{-- ========================= --}}
        {{-- ORDER INFORMATION --}}
        {{-- ========================= --}}

        <div class="card order-form-section">

            <h2>Informasi Order</h2>

            <div class="row order-form-grid">

                <div class="form-group">

                    <label>SPK</label>

                    <input
                        type="text"
                        name="order_id"
                        value="{{ old('order_id') }}"
                        aria-describedby="order-id-error"
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
                            value="{{ old('guest_name') }}"
                            placeholder="Pilih tamu atau ketik nama baru"
                            autocomplete="off"
                            oninput="updateGuestPhone(this)"
                            onchange="updateGuestPhone(this)"
                            required
                        >
                        <input type="hidden" name="guest_id" value="{{ old('guest_id') }}">
                        <input
                            type="hidden"
                            name="guest_name"
                            value="{{ old('guest_name') }}"
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
                            <option value="{{ $orderType }}" @selected(old('order_type') === $orderType)>
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
                        value="{{ old('phone') }}"
                    >

                </div>

                <div
                    class="form-group order-form-deadline-control"
                    data-deadline-control
                    data-deadline-today="{{ today()->toDateString() }}"
                    data-deadline-minimum-days="1"
                >
                    <label for="deadline-date">Deadline tanggal</label>
                    <input
                        id="deadline-date"
                        type="date"
                        class="date-input"
                        name="deadline"
                        value="{{ old('deadline') }}"
                        min="{{ today()->addDay()->toDateString() }}"
                        data-deadline-date
                    >
                    <label for="deadline-days">Atau dalam hari</label>
                    <input
                        id="deadline-days"
                        type="number"
                        min="1"
                        step="1"
                        data-deadline-days
                        aria-label="Jumlah hari sampai deadline"
                    >
                    <small>Ubah hari atau tanggal untuk menyesuaikan deadline.</small>
                </div>

                <div class="form-group">
                    <label>Status Order</label>
                    <span class="order-form-status">New</span>
                </div>
            </div>

            <details
                class="order-form-optional-fields"
                @if(old('guest_address') || old('note') || $errors->has('guest_address') || $errors->has('note')) open @endif
            >
                <summary>Alamat dan catatan tambahan</summary>
                <div class="order-form-optional-grid">
                    <div class="form-group">
                        <label for="guest-address">Alamat Tamu (opsional)</label>
                        <textarea id="guest-address" name="guest_address" rows="2">{{ old('guest_address') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label for="order-note">Catatan Order (opsional)</label>
                        <textarea id="order-note" name="note" rows="2">{{ old('note') }}</textarea>
                    </div>
                </div>
            </details>

        </div>


        {{-- ========================= --}}
        {{-- PRODUCTS --}}
        {{-- ========================= --}}

        <div class="card order-form-section">

            <div class="order-form-product-heading">

                <h2>List Produk</h2>

                <button
                    type="button"
                    class="btn btn-success"
                    onclick="addProduct()"
                >
                    + Add Product
                </button>

            </div>


            <div class="table-wrapper order-form-products-table">
                <table>
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Kategori</th>
                            <th>Ukuran (P x L x T)</th>
                            <th>Qty</th>
                            <th>Harga</th>
                            <th>Warna</th>
                            <th>Sumber</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="product-container"></tbody>
                </table>
            </div>


            <div class="order-form-product-total">
                <strong>
                    Total:
                    Rp <span id="total-display">0</span>
                </strong>
            </div>

            <div class="form-group order-payment-field order-form-payment-field">
                <label for="paid_amount">Pembayaran awal / DP yang sudah diterima (Rp)</label>
                <input
                    id="paid_amount"
                    type="text"
                    inputmode="decimal"
                    class="price-input"
                    data-money-input
                    name="paid_amount"
                    value="{{ old('paid_amount', '0') }}"
                    autocomplete="off"
                    oninput="formatPriceInput(this)"
                >
                <label for="payment_date">Tanggal pembayaran awal</label>
                <input
                    id="payment_date"
                    type="date"
                    name="payment_date"
                    value="{{ old('payment_date', now(auth()->user()->timezone())->toDateString()) }}"
                    max="{{ now(auth()->user()->timezone())->toDateString() }}"
                >
                <small>DP ini dicatat sebagai transaksi pada tanggal yang dipilih. Cicilan berikutnya dapat ditambahkan dari detail order.</small>
            </div>

        </div>


        <div class="order-form-actions">
            <button type="submit" class="btn btn-primary">Simpan Order</button>
            <a href="{{ route('orders.index') }}" class="btn btn-secondary">Batal</a>
        </div>

    </form>

</div>


<datalist id="guest-options">
    @foreach($guests as $guest)
        <option value="{{ $guest->name }}"></option>
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


{{-- ====================================== --}}
{{-- MODAL ADD CATEGORY --}}
{{-- ====================================== --}}

<div
    id="categoryModal"
    class="modal"
>

    <div class="modal-content">

        <h2>Add New Category</h2>

        <div id="category-error" style="color:red;"></div>

        <div class="form-group">

            <label>Category Name</label>

            <input
                type="text"
                id="new-category-name"
                placeholder="Contoh: Dining Chair"
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
                placeholder="Contoh: Natural"
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

    let productIndex = 0;

    let activeCategoryInput = null;
    let activeColorInput = null;
    let activeGuestInput = null;


    const categories = @json($categories);
    const productOptions = @json($productOptions);
    const catalogProducts = @json($catalogProducts);
    const guests = @json($guests);
    const oldItems = @json(is_array(old('items')) ? old('items') : []);


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


    function addProduct(oldItem = null)
    {
        const container =
            document.getElementById('product-container');

        const row = document.createElement('tr');


        row.innerHTML = `

            <td>

                <div class="product-selector">
                    <input
                        type="text"
                        name="items[${productIndex}][product]"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="product-suggestions-${productIndex}"
                        placeholder="Ketik nama produk"
                        oninput="handleProductInput(this)"
                        onfocus="showProductSuggestions(this)"
                        onkeydown="handleProductSuggestionKeydown(event, this)"
                        onblur="closeProductSuggestions(this)"
                        required
                    >
                    <input
                        type="hidden"
                        name="items[${productIndex}][product_id]"
                        class="catalog-product-id"
                    >
                    <div
                        id="product-suggestions-${productIndex}"
                        class="product-suggestions"
                        role="listbox"
                        hidden
                    ></div>
                </div>
                <input
                    type="text"
                    name="items[${productIndex}][sku]"
                    maxlength="100"
                    aria-label="SKU produk (opsional)"
                    placeholder="SKU (opsional)"
                >
                <div
                    class="order-mockup-gallery"
                    data-catalog-mockup-preview
                    hidden
                ></div>

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


                    @if(auth()->user()->canAccessMenu('categories'))
                        <button
                            type="button"
                            class="btn btn-success"
                            onclick="openCategoryModal(this)"
                        >
                            +
                        </button>
                    @endif

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
                    oninput="updateStockSource(this.closest('tr')); calculateTotal()"
                >

            </td>


            <td>

                <input
                    type="text"
                    inputmode="decimal"
                    class="price-input"
                    name="items[${productIndex}][price]"
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
                        oninput="updateCatalogProductForColor(this)"
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

                <input
                    type="hidden"
                    name="items[${productIndex}][source]"
                    value="Order"
                >
                <span data-stock-source-label>Order · Produksi PF</span>

            </td>


            <td><span>New</span></td>


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
        updateStockSource(row);

        if (oldItem) {
            row.querySelector('[name$="[product]"]').value = oldItem.product ?? '';
            row.querySelector('[name$="[product_id]"]').value = oldItem.product_id ?? '';
            row.querySelector('[name$="[sku]"]').value = oldItem.sku ?? '';
            row.querySelector('[name$="[category_id]"]').value = oldItem.category_id ?? '';
            row.querySelector('[name$="[dimensions]"]').value = oldItem.dimensions ?? '';
            const oldCategory = categories.find(category => String(category.id) === String(oldItem.category_id));
            row.querySelector('.category-search').value = oldCategory?.name ?? '';
            row.querySelector('[name$="[qty]"]').value = oldItem.qty ?? '';
            row.querySelector('[name$="[price]"]').value = oldItem.price ?? '';
            row.querySelector('[name$="[color]"]').value = oldItem.color ?? '';
            row.querySelector('[name$="[source]"]').value = oldItem.source ?? 'Order';
            const oldCatalogProduct = catalogProducts.find(product =>
                String(product.id) === String(oldItem.product_id)
            );
            setCatalogMockupPreview(row, oldCatalogProduct);
            updateStockSource(row);
        }

        formatPriceInput(row.querySelector('[name$="[price]"]'), true);

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

    function productSuggestions()
    {
        const catalogSuggestions = catalogProducts.flatMap(product => {
            const colors = (product.colors ?? []).filter(Boolean);

            return (colors.length ? colors : ['']).map(color => ({
                id: product.id,
                name: product.name,
                color,
            }));
        });
        const catalogVariants = new Set(catalogSuggestions.map(suggestion =>
            `${suggestion.name.toLocaleLowerCase()}\u0000${suggestion.color.toLocaleLowerCase()}`
        ));

        return [
            ...catalogSuggestions,
            ...productOptions
                .filter(product => !catalogVariants.has(
                    `${product.name.toLocaleLowerCase()}\u0000${(product.color ?? '').toLocaleLowerCase()}`
                ))
                .map(product => ({
                    id: null,
                    name: product.name,
                    color: product.color ?? '',
                })),
        ];
    }

    function renderProductSuggestions(input)
    {
        const menu = input.closest('.product-selector').querySelector('[role="listbox"]');
        const query = input.value.trim().toLocaleLowerCase();
        const suggestions = productSuggestions()
            .filter(suggestion => `${suggestion.name} ${suggestion.color}`.toLocaleLowerCase().includes(query));

        menu.replaceChildren();
        input.removeAttribute('aria-activedescendant');

        if (suggestions.length === 0) {
            menu.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            return;
        }

        suggestions.forEach((suggestion, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.tabIndex = -1;
            option.id = `${menu.id}-option-${index}`;
            option.className = 'product-suggestion-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.setAttribute(
                'aria-label',
                `${suggestion.name}, ${suggestion.color || 'tanpa warna'}`
            );

            const name = document.createElement('span');
            name.className = 'product-suggestion-name';
            name.textContent = suggestion.name;

            const color = document.createElement('small');
            color.className = 'product-suggestion-color';
            color.textContent = suggestion.color || 'Tanpa warna';

            option.append(name, color);
            option.addEventListener('pointerdown', event => event.preventDefault());
            option.addEventListener('click', () => {
                input.value = suggestion.name;
                updateProductDetails(input, suggestion.id ?? '', suggestion.color);

                closeProductSuggestions(input);
                input.focus();
            });
            menu.appendChild(option);
        });

        menu.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        positionProductSuggestions(input);
    }

    function positionProductSuggestions(input)
    {
        const menu = input.closest('.product-selector').querySelector('[role="listbox"]');
        if (menu.hidden) {
            return;
        }

        const inputBounds = input.getBoundingClientRect();
        const menuWidth = Math.min(Math.max(inputBounds.width, 240), window.innerWidth - 16);
        menu.style.width = `${menuWidth}px`;
        menu.style.left = `${Math.max(8, Math.min(inputBounds.left, window.innerWidth - menuWidth - 8))}px`;

        const menuHeight = menu.getBoundingClientRect().height;
        const spaceBelow = window.innerHeight - inputBounds.bottom;
        const top = spaceBelow >= menuHeight + 8
            ? inputBounds.bottom + 4
            : inputBounds.top >= menuHeight + 8
            ? inputBounds.top - menuHeight - 4
            : 8;
        menu.style.top = `${Math.max(8, top)}px`;
    }

    function showProductSuggestions(input)
    {
        renderProductSuggestions(input);
    }

    function handleProductInput(input)
    {
        updateProductDetails(input);
        renderProductSuggestions(input);
    }

    function handleProductSuggestionKeydown(event, input)
    {
        const menu = input.closest('.product-selector').querySelector('[role="listbox"]');

        if (event.key === 'Escape') {
            closeProductSuggestions(input);
            return;
        }

        if (event.key === 'Enter' && ! menu.hidden) {
            const activeOption = menu.querySelector('[aria-selected="true"]')
                ?? menu.querySelector('[role="option"]');
            if (activeOption) {
                event.preventDefault();
                activeOption.click();
            }

            return;
        }

        if (! ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        if (menu.hidden) {
            renderProductSuggestions(input);
        }

        const options = [...menu.querySelectorAll('[role="option"]')];
        if (options.length === 0) {
            return;
        }

        const activeIndex = options.findIndex(option => option.getAttribute('aria-selected') === 'true');
        const nextIndex = event.key === 'ArrowDown'
            ? (activeIndex + 1) % options.length
            : (activeIndex <= 0 ? options.length - 1 : activeIndex - 1);
        options.forEach((option, index) => option.setAttribute('aria-selected', String(index === nextIndex)));
        input.setAttribute('aria-activedescendant', options[nextIndex].id);
        options[nextIndex].scrollIntoView({ block: 'nearest' });
    }

    function closeProductSuggestions(input)
    {
        const menu = input.closest('.product-selector').querySelector('[role="listbox"]');
        menu.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function repositionOpenProductSuggestions()
    {
        document.querySelectorAll('.product-selector input[aria-expanded="true"]')
            .forEach(positionProductSuggestions);
    }

    function setCatalogMockupPreview(row, product)
    {
        const preview = row.querySelector('[data-catalog-mockup-preview]');
        if (!preview) {
            return;
        }

        preview.replaceChildren();

        (product?.mockup_urls ?? []).forEach(mockupUrl => {
            const image = document.createElement('img');
            image.className = 'product-catalog-photo';
            image.src = mockupUrl;
            image.alt = `Mockup ${product.name}`;
            preview.appendChild(image);
        });

        preview.hidden = (product?.mockup_urls ?? []).length === 0;
    }

    function updateStockSource(row)
    {
        if (!row) {
            return;
        }

        const sourceInput = row.querySelector('[name$="[source]"]');
        const productId = row.querySelector('[name$="[product_id]"]')?.value;
        const quantity = Number(row.querySelector('[name$="[qty]"]')?.value ?? 1);
        const product = catalogProducts.find(candidate => String(candidate.id) === String(productId));
        const stockQuantity = Number(product?.stock_bali_quantity ?? 0)
            + Number(product?.stock_factory_quantity ?? 0);
        const canUseStock = product && product.has_factory_sale_price && stockQuantity > 0;
        const stockUsed = Math.min(stockQuantity, quantity);

        if (sourceInput) {
            sourceInput.value = canUseStock ? 'Stock' : 'Order';
        }

        const sourceLabel = row.querySelector('[data-stock-source-label]');
        if (sourceLabel) {
            sourceLabel.textContent = canUseStock && stockUsed < quantity
                ? `Stock ${stockUsed} · Produksi ${quantity - stockUsed}`
                : canUseStock
                    ? `Stock · ${stockUsed} unit`
                    : 'Order · Produksi PF';
        }
    }

    function updateProductDetails(input, selectedCatalogProductId = null, selectedColor = null)
    {
        const row = input.closest('tr');
        const categoryInput = row.querySelector('.category-search');
        const categoryIdInput = row.querySelector('[name$="[category_id]"]');
        const productIdInput = row.querySelector('[name$="[product_id]"]');
        const skuInput = row.querySelector('[name$="[sku]"]');
        const dimensionsInput = row.querySelector('[name$="[dimensions]"]');
        const priceInput = row.querySelector('[name$="[price]"]');
        const colorInput = row.querySelector('[name$="[color]"]');
        const previouslySelectedCatalogId = productIdInput.value;
        const matchingCatalogProduct = catalogProducts.find(product =>
            selectedCatalogProductId === ''
                ? false
                : selectedCatalogProductId !== null
                ? String(product.id) === String(selectedCatalogProductId)
                : product.name.toLocaleLowerCase() === input.value.trim().toLocaleLowerCase()
        );
        const requestedColor = (selectedColor ?? colorInput.value).trim().toLocaleLowerCase();
        const matchingProduct = productOptions.find(product =>
            product.name.toLocaleLowerCase() === input.value.trim().toLocaleLowerCase()
            && (! requestedColor || (product.color ?? '').toLocaleLowerCase() === requestedColor)
        ) ?? productOptions.find(product =>
            product.name.toLocaleLowerCase() === input.value.trim().toLocaleLowerCase()
        );
        const previouslyAutofilledProduct = categoryInput.dataset.autofilledProduct;

        if (matchingCatalogProduct) {
            setCatalogMockupPreview(row, matchingCatalogProduct);
            productIdInput.value = matchingCatalogProduct.id;
            skuInput.value = matchingCatalogProduct.sku ?? '';
            categoryInput.value = matchingCatalogProduct.category_name ?? '';
            categoryInput.dataset.autofilledProduct = matchingCatalogProduct.name;
            categoryIdInput.value = matchingCatalogProduct.category_id ?? '';
            dimensionsInput.value = matchingCatalogProduct.dimensions || matchingProduct?.dimensions || '';
            priceInput.value = matchingCatalogProduct.price ?? '';
            formatPriceInput(priceInput, true);
            colorInput.value = selectedColor
                ?? (matchingCatalogProduct.colors.length === 1 ? matchingCatalogProduct.colors[0] : '');
        } else {
            setCatalogMockupPreview(row, null);
            productIdInput.value = '';
            if (previouslySelectedCatalogId !== '') {
                categoryInput.value = '';
                categoryIdInput.value = '';
                skuInput.value = '';
                dimensionsInput.value = '';
                priceInput.value = '';
                colorInput.value = '';
                delete categoryInput.dataset.autofilledProduct;
            }

            if (matchingProduct) {
                skuInput.value = matchingProduct.sku ?? '';
                categoryInput.value = matchingProduct.category_name ?? '';
                categoryInput.dataset.autofilledProduct = matchingProduct.name;
                categoryIdInput.value = matchingProduct.category_id ?? '';
                dimensionsInput.value = matchingProduct.dimensions ?? '';
            } else if (previouslyAutofilledProduct) {
                skuInput.value = '';
                categoryInput.value = '';
                categoryIdInput.value = '';
                dimensionsInput.value = '';
                delete categoryInput.dataset.autofilledProduct;
            }
        }

        updateStockSource(row);
        calculateTotal();
    }

    function updateCatalogProductForColor(input)
    {
        const row = input.closest('tr');
        const productInput = row.querySelector('[name$="[product]"]');
        const productIdInput = row.querySelector('[name$="[product_id]"]');
        const dimensionsInput = row.querySelector('[name$="[dimensions]"]');
        const productName = productInput.value.trim().toLocaleLowerCase();
        const color = input.value.trim();
        const selectedProduct = catalogProducts.find(product =>
            String(product.id) === String(productIdInput.value)
            && product.name.toLocaleLowerCase() === productName
        );
        const fallbackDimensions = dimensionsInput.value.trim() || selectedProduct?.dimensions || '';

        if (!productName || !color) {
            return;
        }

        const matchingVariant = catalogProducts.find(product =>
            product.name.toLocaleLowerCase() === productName
            && product.colors.some(productColor =>
                productColor.toLocaleLowerCase() === color.toLocaleLowerCase()
            )
        );

        if (matchingVariant) {
            updateProductDetails(productInput, matchingVariant.id);
            if (!matchingVariant.dimensions) {
                dimensionsInput.value = fallbackDimensions;
            }
            input.value = color;
            return;
        }

        if (selectedProduct) {
            productIdInput.value = '';
            setCatalogMockupPreview(row, null);
        }
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
        const guestIdInput = guestWrapper.querySelector('[name="guest_id"]');
        const previousGuestId = guestIdInput.value;
        const form = input.closest('form');

        guestIdInput.value = matchingGuest?.id ?? '';
        guestWrapper.querySelector('[name="guest_name"]').value = matchingGuest?.name ?? input.value.trim();
        form.querySelector('[name="guest_address"]').value = matchingGuest?.address ?? '';
        setGuestSource(matchingGuest?.guest_source ?? '');

        const phoneInput = form.querySelector('[name="phone"]');
        const matchingPhone = matchingGuest?.phone ?? matchingGuest?.phones?.at(-1)?.phone ?? '';

        if (matchingGuest && matchingPhone) {
            phoneInput.value = matchingPhone;
        } else if (previousGuestId && String(previousGuestId) !== String(matchingGuest?.id ?? '')) {
            phoneInput.value = '';
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


        const errorBox =
            document.getElementById('category-error');


        if (!name) {

            errorBox.innerText =
                'Category name wajib diisi.';

            return;
        }


        try {

            const response =
                await fetch('{{ route('categories.store') }}', {

                    method: 'POST',

                    headers: {

                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json',

                        'X-CSRF-TOKEN':
                            '{{ csrf_token() }}'

                    },

                    body: JSON.stringify({
                        name: name
                    })

                });


            const data =
                await response.json();


            if (!response.ok) {

                if (data.errors?.name) {

                    errorBox.innerText =
                        data.errors.name[0];

                } else {

                    errorBox.innerText =
                        'Gagal menambahkan category.';

                }

                return;
            }


            const category =
                data.category;


            categories.push(category);

            if (activeCategoryInput) {
                activeCategoryInput.value = category.name;
                updateCategoryId(activeCategoryInput);
            }


            closeCategoryModal();


        } catch (error) {

            console.error(error);

            errorBox.innerText =
                'Terjadi kesalahan server.';

        }
    }


    // Otomatis buat satu product
    // ketika halaman dibuka

    if (oldItems.length > 0) {
        oldItems.forEach(oldItem => addProduct(oldItem));
    } else {
        addProduct();
    }

    window.addEventListener('scroll', repositionOpenProductSuggestions, true);
    window.addEventListener('resize', repositionOpenProductSuggestions);

    document.querySelectorAll('[data-money-input]').forEach(input => {
        formatPriceInput(input);
    });

</script>

@endsection