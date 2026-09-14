<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Edit Order</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f5f6f8;
            padding: 30px;
        }

        .container {
            max-width: 900px;
            margin: auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
        }

        textarea {
            min-height: 100px;
        }

        .item {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .remove-item {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        .add-item {
            background: #198754;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-save {
            background: #222;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
        }

        .back {
            display: inline-block;
            margin-bottom: 20px;
            text-decoration: none;
            color: #333;
        }

        .error {
            background: #f8d7da;
            color: #842029;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
        }

        .current-image {
            max-width: 300px;
            margin-top: 10px;
            border-radius: 8px;
        }

    </style>

</head>

<body>

<div class="container">

    <a href="{{ route('orders.index') }}" class="back">
        ← Back to Orders
    </a>

    <h1>Edit Order</h1>

    @if($errors->any())

        <div class="error">

            <ul>

                @foreach($errors->all() as $error)

                    <li>{{ $error }}</li>

                @endforeach

            </ul>

        </div>

    @endif

    <form action="{{ route('orders.update', $order) }}"
          method="POST"
          enctype="multipart/form-data">

        @csrf
        @method('PUT')

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

            <input
                type="text"
                name="guest_name"
                value="{{ old('guest_name', $order->guest_name) }}"
                required
            >

        </div>

        <div class="form-group">

            <label>No. Telpon</label>

            <input
                type="text"
                name="phone"
                value="{{ old('phone', $order->phone) }}"
                required
            >

        </div>

        <div class="form-group">

            <label>List Barang</label>

            <div id="items">

                @foreach($order->items ?? [] as $index => $item)

                    <div class="item">

                        <input
                            type="text"
                            name="items[{{ $index }}][name]"
                            value="{{ $item['name'] ?? '' }}"
                            placeholder="Nama barang"
                            required
                        >

                        <input
                            type="number"
                            name="items[{{ $index }}][quantity]"
                            value="{{ $item['quantity'] ?? 1 }}"
                            min="1"
                            required
                        >

                        <button
                            type="button"
                            class="remove-item"
                            onclick="removeItem(this)">
                            X
                        </button>

                    </div>

                @endforeach

                @if(empty($order->items))

                    <div class="item">

                        <input
                            type="text"
                            name="items[0][name]"
                            placeholder="Nama barang"
                        >

                        <input
                            type="number"
                            name="items[0][quantity]"
                            value="1"
                            min="1"
                        >

                        <button
                            type="button"
                            class="remove-item"
                            onclick="removeItem(this)">
                            X
                        </button>

                    </div>

                @endif

            </div>

            <button
                type="button"
                class="add-item"
                onclick="addItem()">

                + Tambah Barang

            </button>

        </div>

        <div class="form-group">

            <label>Warna</label>

            <input
                type="text"
                name="color"
                value="{{ old('color', $order->color) }}"
            >

        </div>

        <div class="form-group">

            <label>Tanggal Gambar</label>

            <input
                type="date"
                name="image_date"
                value="{{ old(
                    'image_date',
                    $order->image_date?->format('Y-m-d')
                ) }}"
            >

        </div>

        <div class="form-group">

            <label>Gambar Baru</label>

            <input
                type="file"
                name="image"
                accept="image/*"
            >

            @if($order->image)

                <div>

                    <p>Gambar sekarang:</p>

                    <img
                        src="{{ asset('storage/' . $order->image) }}"
                        class="current-image"
                    >

                </div>

            @endif

        </div>

        <div class="form-group">

            <label>Deadline</label>

            <input
                type="date"
                name="deadline"
                value="{{ old(
                    'deadline',
                    $order->deadline?->format('Y-m-d')
                ) }}"
            >

        </div>

        <div class="form-group">

            <label>

                <input
                    type="checkbox"
                    name="urgent"
                    value="1"
                    style="width:auto;"
                    {{ old('urgent', $order->urgent) ? 'checked' : '' }}
                >

                Urgent

            </label>

        </div>

        <div class="form-group">

            <label>Status</label>

            <select name="status">

                @foreach([
                    'Pending',
                    'Design',
                    'Production',
                    'Finishing',
                    'Ready',
                    'Completed',
                    'Cancelled'
                ] as $status)

                    <option
                        value="{{ $status }}"
                        {{ old('status', $order->status) == $status ? 'selected' : '' }}>

                        {{ $status }}

                    </option>

                @endforeach

            </select>

        </div>

        <div class="form-group">

            <label>Note</label>

            <textarea name="note">{{ old('note', $order->note) }}</textarea>

        </div>

        <button
            type="submit"
            class="btn-save">

            Update Order

        </button>

    </form>

</div>

<script>

let itemIndex = {{ count($order->items ?? []) }};

function addItem() {

    const container = document.getElementById('items');

    const div = document.createElement('div');

    div.className = 'item';

    div.innerHTML = `
        <input
            type="text"
            name="items[${itemIndex}][name]"
            placeholder="Nama barang"
            required
        >

        <input
            type="number"
            name="items[${itemIndex}][quantity]"
            value="1"
            min="1"
            required
        >

        <button
            type="button"
            class="remove-item"
            onclick="removeItem(this)">
            X
        </button>
    `;

    container.appendChild(div);

    itemIndex++;
}

function removeItem(button) {

    const items = document.querySelectorAll('.item');

    if (items.length > 1) {
        button.parentElement.remove();
    }

}

</script>

</body>

</html>