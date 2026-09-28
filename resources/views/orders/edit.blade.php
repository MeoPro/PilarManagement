<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <title>Edit Order</title>

    <style>

        body {
            font-family: Arial;
            margin: 30px;
            background: #f5f5f5;
        }

        .container {
            max-width: 1400px;
            margin: auto;
        }

        .card {
            background: white;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
        }

        input,
        select,
        textarea {
            padding: 9px;
            width: 100%;
            box-sizing: border-box;
        }

        .price-input {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .date-input {
            min-height: 42px;
            border: 1px solid #cbd5d1;
            border-radius: 5px;
            background: #fff;
            color: #17211d;
            font: inherit;
            font-variant-numeric: tabular-nums;
            accent-color: #176b52;
            cursor: pointer;
        }

        .date-input:focus {
            outline: 2px solid #176b52;
            outline-offset: 1px;
            border-color: #176b52;
        }

        .date-input::-webkit-calendar-picker-indicator {
            padding: 4px;
            border-radius: 4px;
            cursor: pointer;
        }

        .date-input::-webkit-calendar-picker-indicator:hover {
            background: #e8f2ee;
        }

        label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 8px;
            border: 1px solid #ddd;
        }

        .btn {
            padding: 9px 14px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #222;
            color: white;
        }

        .btn-success {
            background: #198754;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .category-wrapper {
            display: flex;
            gap: 5px;
        }

        .guest-wrapper {
            display: flex;
            gap: 5px;
        }

        .guest-wrapper input {
            flex: 1;
            min-width: 0;
        }

        .category-wrapper select {
            flex: 1;
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .modal-content {
            background: white;
            padding: 25px;
            width: 400px;
            border-radius: 8px;
        }

    </style>

</head>


<body>

<div class="container">

    <h1>
        Edit Order {{ $order->order_id }}
    </h1>


    @if($errors->any())

        <div style="color:red;">

            <ul>

                @foreach($errors->all() as $error)

                    <li>{{ $error }}</li>

                @endforeach

            </ul>

        </div>

    @endif


    <form
        action="{{ route('orders.update', $order) }}"
        method="POST"
        enctype="multipart/form-data"
    >

        @csrf
        @method('PUT')


        {{-- ORDER --}}

        <div class="card">

            <h2>Order Information</h2>

            <div class="row">

                <div class="form-group">

                    <label>Order ID</label>

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
                            placeholder="Cari atau pilih tamu"
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
                        <button
                            type="button"
                            class="btn btn-success"
                            onclick="openGuestModal(this)"
                            aria-label="Tambah tamu"
                        >
                            +
                        </button>
                    </div>

                </div>


                <div class="form-group">

                    <label>Sumber Tamu</label>

                    <select name="guest_source" required>
                        <option value="">-- Pilih Sumber Tamu --</option>
                        @foreach(['Lokal', 'Online', 'Touris'] as $guestSource)
                            <option
                                value="{{ $guestSource }}"
                                @selected(old('guest_source', $order->guest_source) === $guestSource)
                            >
                                {{ $guestSource }}
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

                    <label>Deadline</label>

                    <input
                        type="date"
                        class="date-input"
                        name="deadline"
                        value="{{ old('deadline', $order->deadline?->format('Y-m-d')) }}"
                    >

                </div>


                <div class="form-group">

                    <label>Status Order</label>

                    <select name="status">

                        @foreach([
                            'None',
                            'Proses Produksi',
                            'Finishing',
                            'Selesai',
                            'Cancel'
                        ] as $status)

                            <option
                                value="{{ $status }}"
                                @selected(old('status', $order->status) === $status)
                            >
                                {{ $status }}
                            </option>

                        @endforeach

                    </select>

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
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Warna</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th>Gambar</th>
                        <th>Action</th>

                    </tr>

                </thead>


                <tbody id="product-container">

                @foreach($order->items as $index => $item)

                    <tr>

                        <input
                            type="hidden"
                            name="items[{{ $index }}][id]"
                            value="{{ $item->id }}"
                        >


                        <td>

                            <input
                                type="text"
                                name="items[{{ $index }}][product]"
                                value="{{ $item->product }}"
                                required
                            >

                        </td>


                        <td>

                            <div class="category-wrapper">

                                <input
                                    type="text"
                                    class="category-search"
                                    list="category-options"
                                    autocomplete="off"
                                    placeholder="Cari kategori"
                                    value="{{ $item->category?->name }}"
                                    oninput="updateCategoryId(this)"
                                >

                                <input
                                    type="hidden"
                                    name="items[{{ $index }}][category_id]"
                                    value="{{ $item->category_id }}"
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
                                type="number"
                                name="items[{{ $index }}][qty]"
                                value="{{ $item->qty }}"
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
                                name="items[{{ $index }}][price]"
                                value="{{ $item->price }}"
                                required
                                oninput="formatPriceInput(this); calculateTotal()"
                            >

                        </td>


                        <td>

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

                        </td>


                        <td>

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

                        </td>


                        <td>

                            <select
                                name="items[{{ $index }}][status]"
                            >

                                @foreach(['None','Produksi','Distribusi','Ready'] as $status)

                                    <option
                                        value="{{ $status }}"
                                        @selected($item->status === $status)
                                    >
                                        {{ $status }}
                                    </option>

                                @endforeach

                            </select>

                        </td>


                        <td>

                            @if($item->image)

                                <div>

                                    <a
                                        href="{{ asset('storage/' . $item->image) }}"
                                        target="_blank"
                                    >
                                        Current Image
                                    </a>

                                </div>

                            @endif


                            <input
                                type="file"
                                name="items[{{ $index }}][image]"
                                accept="image/*"
                            >

                        </td>


                        <td>

                            <button
                                type="button"
                                class="btn btn-danger"
                                onclick="removeProduct(this)"
                            >
                                X
                            </button>

                        </td>

                    </tr>

                @endforeach

                </tbody>

            </table>


            <h2 style="text-align:right;">

                Total:
                Rp <span id="total-display">0</span>

            </h2>

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
                    required
                >
            </td>

            <td>

                <div class="category-wrapper">

                    <input
                        type="text"
                        class="category-search"
                        list="category-options"
                        autocomplete="off"
                        placeholder="Cari kategori"
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

                <select
                    name="items[${productIndex}][status]"
                >

                    <option value="None">None</option>
                    <option value="Produksi">Produksi</option>
                    <option value="Distribusi">Distribusi</option>
                    <option value="Ready">Ready</option>

                </select>

            </td>

            <td>

                <input
                    type="file"
                    name="items[${productIndex}][image]"
                    accept="image/*"
                >

            </td>

            <td>

                <button
                    type="button"
                    class="btn btn-danger"
                    onclick="removeProduct(this)"
                >
                    X
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


    function updateGuestPhone(input)
    {
        const normalizedValue = input.value.trim().toLocaleLowerCase();
        const matchingGuest = guests.find(guest => guest.name.toLocaleLowerCase() === normalizedValue);
        const guestWrapper = input.closest('.guest-wrapper');

        guestWrapper.querySelector('[name="guest_id"]').value = matchingGuest?.id ?? '';
        guestWrapper.querySelector('[name="guest_name"]').value = matchingGuest?.name ?? input.value.trim();

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
            }

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
            const option = document.createElement('option');
            option.value = category.name;
            document.getElementById('category-options').appendChild(option);

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


    document.querySelector('form').addEventListener('submit', function () {
        this.querySelectorAll('[name*="[price]"]').forEach(input => {
            input.value = input.value.replace(/,/g, '');
        });
    });


    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[name*="[price]"]').forEach(input => {
            formatPriceInput(input, true);
        });

        calculateTotal();
    });

</script>

</body>

</html>