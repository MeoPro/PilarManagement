<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Orders</title>

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

        th {
            background: #f5f5f5;
        }

        .btn {
            padding: 7px 12px;
            border-radius: 5px;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }

        .primary {
            background: #222;
            color: white;
        }

        .warning {
            background: #ffc107;
            color: black;
        }

        .danger {
            background: #dc3545;
            color: white;
        }

        .urgent {
            background: #dc3545;
            color: white;
            padding: 5px 8px;
            border-radius: 4px;
        }

    </style>

</head>

<body>

<h1>Orders</h1>


@if(session('success'))

    <div style="color:green;margin-bottom:20px;">
        {{ session('success') }}
    </div>

@endif


<a
    href="{{ route('orders.create') }}"
    class="btn primary"
>
    + Create Order
</a>


<a
    href="{{ route('categories.index') }}"
    class="btn"
>
    Categories
</a>


<br><br>


<table>

    <thead>

        <tr>

            <th>Order ID</th>

            <th>Tamu</th>

            <th>No. Telpon</th>

            <th>Products</th>

            <th>Total</th>

            <th>Deadline</th>

            <th>Last Edit</th>

            <th>Status</th>

            <th>Action</th>

        </tr>

    </thead>


    <tbody>

    @forelse($orders as $order)

        <tr>

            <td>
                {{ $order->order_id }}
            </td>


            <td>
                {{ $order->guest_name }}
            </td>


            <td>
                {{ $order->phone }}
            </td>


            <td>

                @foreach($order->items as $item)

                    <div style="margin-bottom:5px;">

                        <strong>
                            {{ $item->product }}
                        </strong>

                        × {{ $item->qty }}

                        @if($item->category)

                            <small>
                                ({{ $item->category->name }})
                            </small>

                        @endif

                    </div>

                @endforeach

            </td>


            <td>

                Rp {{ number_format($order->total_order, 0, ',', '.') }}

            </td>


            <td>

                {{ $order->deadline?->format('d/m/Y') ?? '-' }}

            </td>


            <td>

                {{ $order->last_edit?->format('d/m/Y H:i') ?? '-' }}

            </td>


            <td>

                @if($order->is_urgent)

                    <span class="urgent">
                        URGENT
                    </span>

                @else

                    {{ $order->status }}

                @endif

            </td>


            <td>

                <a
                    href="{{ route('orders.show', $order) }}"
                    class="btn"
                >
                    View
                </a>


                <a
                    href="{{ route('orders.edit', $order) }}"
                    class="btn warning"
                >
                    Edit
                </a>


                <form
                    action="{{ route('orders.destroy', $order) }}"
                    method="POST"
                    style="display:inline;"
                >

                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="btn danger"
                        onclick="return confirm('Hapus order ini?')"
                    >
                        Delete
                    </button>

                </form>

            </td>

        </tr>

    @empty

        <tr>

            <td colspan="9">
                Belum ada order.
            </td>

        </tr>

    @endforelse

    </tbody>

</table>


<br>

{{ $orders->links() }}

</body>

</html>