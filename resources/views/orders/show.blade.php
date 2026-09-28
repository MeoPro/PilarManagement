<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>
        Order {{ $order->order_id }}
    </title>

    <style>

        body {
            font-family: Arial;
            margin: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 10px;
        }

        .urgent {
            background: red;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
        }

    </style>

</head>

<body>

<h1>
    Order {{ $order->order_id }}
</h1>


<p>
    <strong>Tamu:</strong>
    {{ $order->guest_name }}
</p>


<p>
    <strong>Sumber Tamu:</strong>
    {{ $order->guest_source ?? '-' }}
</p>


<p>
    <strong>No. Telpon:</strong>
    {{ $order->phone ?? '-' }}
</p>


<p>
    <strong>Deadline:</strong>
    {{ $order->deadline?->format('d M Y') ?? '-' }}
</p>


<p>
    <strong>Last Edit:</strong>
    {{ $order->last_edit?->format('d M Y H:i') ?? '-' }}
</p>


<p>

    <strong>Status:</strong>

    @if($order->is_urgent)

        <span class="urgent">
            URGENT
        </span>

    @else

        {{ $order->status }}

    @endif

</p>


<h2>List Produk</h2>


<table>

    <thead>

        <tr>

            <th>Produk</th>
            <th>Category</th>
            <th>Qty</th>
            <th>Harga</th>
            <th>Subtotal</th>
            <th>Warna</th>
            <th>Sumber</th>
            <th>Status</th>
            <th>Gambar</th>

        </tr>

    </thead>


    <tbody>

    @foreach($order->items as $item)

        <tr>

            <td>
                {{ $item->product }}
            </td>

            <td>
                {{ $item->category?->name ?? '-' }}
            </td>

            <td>
                {{ $item->qty }}
            </td>

            <td>
                Rp {{ number_format($item->price, 0, ',', '.') }}
            </td>

            <td>
                Rp {{ number_format($item->subtotal, 0, ',', '.') }}
            </td>

            <td>
                {{ $item->color ?? '-' }}
            </td>

            <td>
                {{ $item->source }}
            </td>

            <td>
                {{ $item->status }}
            </td>

            <td>

                @if($item->image)

                    <a
                        href="{{ asset('storage/' . $item->image) }}"
                        target="_blank"
                    >
                        View Image
                    </a>

                @else

                    -

                @endif

            </td>

        </tr>

    @endforeach

    </tbody>

</table>


<h2 style="text-align:right;">

    Total:

    Rp {{ number_format($order->total_order, 0, ',', '.') }}

</h2>


@if($order->note)

    <h3>Note</h3>

    <p>
        {{ $order->note }}
    </p>

@endif


<br>


<a href="{{ route('orders.index') }}">
    ← Back
</a>

&nbsp;

<a href="{{ route('orders.edit', $order) }}">
    Edit Order
</a>

</body>

</html>