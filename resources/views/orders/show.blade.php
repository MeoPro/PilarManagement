<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>{{ $order->order_id }}</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f5f6f8;
            padding: 30px;
        }

        .container {
            max-width: 900px;
            margin: auto;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .row {
            display: grid;
            grid-template-columns: 200px 1fr;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .label {
            font-weight: bold;
        }

        .urgent {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 6px 10px;
            border-radius: 5px;
        }

        .normal {
            display: inline-block;
            background: #e9ecef;
            padding: 6px 10px;
            border-radius: 5px;
        }

        .order-image {
            max-width: 500px;
            width: 100%;
            border-radius: 8px;
            margin-top: 10px;
        }

        .btn {
            display: inline-block;
            padding: 10px 15px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            margin-right: 5px;
        }

        .btn-back {
            background: #6c757d;
        }

        .btn-edit {
            background: #f0ad4e;
        }

        li {
            margin-bottom: 8px;
        }

    </style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>
            Order {{ $order->order_id }}
        </h1>

        <a
            href="{{ route('orders.index') }}"
            class="btn btn-back">

            ← Back

        </a>

        <a
            href="{{ route('orders.edit', $order) }}"
            class="btn btn-edit">

            Edit

        </a>

    </div>

    <div class="card">

        <div class="row">

            <div class="label">
                Order ID
            </div>

            <div>
                {{ $order->order_id }}
            </div>

        </div>

        <div class="row">

            <div class="label">
                Tamu
            </div>

            <div>
                {{ $order->guest_name }}
            </div>

        </div>

        <div class="row">

            <div class="label">
                No. Telpon
            </div>

            <div>
                {{ $order->phone }}
            </div>

        </div>

        <div class="row">

            <div class="label">
                List Barang
            </div>

            <div>

                @if($order->items)

                    <ul>

                        @foreach($order->items as $item)

                            <li>
                                {{ $item['name'] }}
                                — {{ $item['quantity'] }} pcs
                            </li>

                        @endforeach

                    </ul>

                @else

                    -

                @endif

            </div>

        </div>

        <div class="row">

            <div class="label">
                Warna
            </div>

            <div>
                {{ $order->color ?? '-' }}
            </div>

        </div>

        <div class="row">

            <div class="label">
                Tanggal Gambar
            </div>

            <div>
                {{ $order->image_date?->format('d F Y') ?? '-' }}
            </div>

        </div>

        <div class="row">

            <div class="label">
                Deadline
            </div>

            <div>
                {{ $order->deadline?->format('d F Y') ?? '-' }}
            </div>

        </div>

        <div class="row">

            <div class="label">
                Urgent
            </div>

            <div>

                @if($order->urgent)

                    <span class="urgent">
                        URGENT
                    </span>

                @else

                    <span class="normal">
                        Normal
                    </span>

                @endif

            </div>

        </div>

        <div class="row">

            <div class="label">
                Status
            </div>

            <div>
                <strong>{{ $order->status }}</strong>
            </div>

        </div>

        <div class="row">

            <div class="label">
                Note
            </div>

            <div>
                {!! nl2br(e($order->note ?? '-')) !!}
            </div>

        </div>

    </div>

    @if($order->image)

        <div class="card">

            <h2>
                Gambar dari WhatsApp
            </h2>

            <img
                src="{{ asset('storage/' . $order->image) }}"
                class="order-image"
            >

        </div>

    @endif

</div>

</body>

</html>