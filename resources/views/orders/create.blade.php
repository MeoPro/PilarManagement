<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Create Order</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            background: #f5f5f5;
        }

        .container {
            max-width: 1300px;
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

    <h1>Create Order</h1>

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
        action="{{ route('orders.store') }}"
        method="POST"
        enctype="multipart/form-data"
    >

        @csrf


        {{-- ========================= --}}
        {{-- ORDER INFORMATION --}}
        {{-- ========================= --}}

        <div class="card">

            <h2>Order Information</h2>

            <div class="row">

                <div class="form-group">

                    <label>Order ID</label>

                    <input
                        type="text"
                        name="order_id"
                        value="{{ old('order_id') }}"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Tamu</label>

                    <input
                        type="text"
                        name="guest_name"
                        value="{{ old('guest_name') }}"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>No. Telpon</label>

                    <input
                        type="text"
                        name="phone"
                        value="{{ old('phone') }}"
                    >

                </div>


                <div class="form-group">

                    <label>Deadline</label>

                    <input
                        type="date"
                        name="deadline"
                        value="{{ old('deadline') }}"
                    >

                </div>


                <div class="form-group">

                    <label>Status Order</label>

                    <select name="status">

                        <option value="None">
                            None
                        </option>

                        <option value="Proses Produksi">
                            Proses Produksi
                        </option>

                        <option value="Finishing">
                            Finishing
                        </option>

                        <option value="Selesai">
                            Selesai
                        </option>

                        <option value="Cancel">
                            Cancel
                        </option>

                    </select>

                </div>

            </div>


            <div class="form-group">

                <label>Note</label>

                <textarea
                    name="note"
                    rows="4"
                >{{ old('note') }}</textarea>

            </div>

        </div>


        {{-- ========================= --}}
        {{-- PRODUCTS --}}
        {{-- ========================= --}}

        <div class="card">

            <div style="display:flex;justify-content:space-between;align-items:center;">

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

                    {{-- Product row generated by JS --}}

                </tbody>

            </table>


            <div style="text-align:right;margin-top:20px;">

                <h2>
                    Total:
                    Rp <span id="total-display">0</span>
                </h2>

            </div>

        </div>


        <button
            type="submit"
            class="btn btn-primary"
        >
            Save Order
        </button>


        <a
            href="{{ route('orders.index') }}"
            class="btn btn-secondary"
        >
            Cancel
        </a>

    </form>

</div>


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


<script>

    let productIndex = 0;

    let activeCategorySelect = null;


    const categories = @json($categories);


    function addProduct()
    {
        const container =
            document.getElementById('product-container');


        let options = `
            <option value="">-- Select Category --</option>
        `;


        categories.forEach(category => {

            options += `
                <option value="${category.id}">
                    ${category.name}
                </option>
            `;

        });


        const row = document.createElement('tr');


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

                    <select
                        name="items[${productIndex}][category_id]"
                        class="category-select"
                    >

                        ${options}

                    </select>


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
                    type="number"
                    name="items[${productIndex}][price]"
                    value="0"
                    min="0"
                    required
                    oninput="calculateTotal()"
                >

            </td>


            <td>

                <input
                    type="text"
                    name="items[${productIndex}][color]"
                >

            </td>


            <td>

                <select
                    name="items[${productIndex}][source]"
                    required
                >

                    <option value="Stock">
                        Stock
                    </option>

                    <option value="Order" selected>
                        Order
                    </option>

                    <option value="Mix">
                        Mix
                    </option>

                </select>

            </td>


            <td>

                <select
                    name="items[${productIndex}][status]"
                    required
                >

                    <option value="None">
                        None
                    </option>

                    <option value="Produksi">
                        Produksi
                    </option>

                    <option value="Distribusi">
                        Distribusi
                    </option>

                    <option value="Ready">
                        Ready
                    </option>

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
                        row.querySelector('[name*="[price]"]')?.value
                    ) || 0;


                total += qty * price;

            });


        document.getElementById('total-display').innerText =
            new Intl.NumberFormat('id-ID').format(total);
    }


    function openCategoryModal(button)
    {
        activeCategorySelect =
            button
                .closest('.category-wrapper')
                .querySelector('.category-select');


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


            // Masukkan category baru
            // ke semua dropdown category

            document
                .querySelectorAll('.category-select')
                .forEach(select => {

                    const option =
                        document.createElement('option');

                    option.value =
                        category.id;

                    option.textContent =
                        category.name;

                    select.appendChild(option);

                });


            // Pilih category baru
            // pada product yang sedang aktif

            if (activeCategorySelect) {

                activeCategorySelect.value =
                    category.id;

            }


            // Update array JS
            categories.push(category);


            closeCategoryModal();


        } catch (error) {

            console.error(error);

            errorBox.innerText =
                'Terjadi kesalahan server.';

        }
    }


    // Otomatis buat satu product
    // ketika halaman dibuka

    document.addEventListener('DOMContentLoaded', function () {

        addProduct();

    });

</script>

</body>
</html>