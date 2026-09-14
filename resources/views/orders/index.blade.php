<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Orders - Pilar Management</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f6f8;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1500px;
            margin: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        h1 {
            margin: 0;
        }

        .btn {
            padding: 10px 16px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary {
            background: #222;
            color: white;
        }

        .btn-warning {
            background: #f0ad4e;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-info {
            background: #0d6efd;
            color: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }

        th,
        td {
            padding: 14px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #222;
            color: white;
        }

        .urgent {
            background: #dc3545;
            color: white;
            padding: 5px 8px;
            border-radius: 5px;
            font-size: 12px;
        }

        .normal {
            background: #e9ecef;
            padding: 5px 8px;
            border-radius: 5px;
            font-size: 12px;
        }

        .status {
            padding: 5px 9px;
            border-radius: 5px;
            background: #eee;
            font-size: 12px;
        }

        .actions {
            display: flex;
            gap: 5px;
        }

        .success {
            background: #d1e7dd;
            color: #0f5132;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 6px;
        }

        .pagination {
            margin-top: 20px;
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>Orders</h1>

        <a href="{{ route('orders.create') }}"
           class="btn btn-primary">
            + Add Order
        </a>
    </div>

    @if(session('success'))
        <div class="success">
            {{ session('success') }}
        </div>
    @endif

    <table>

        <thead>
        <tr>
            <th>Order ID</th>
            <th>Tamu</th>
            <th>No. Telp</th>
            <th>Barang</th>
            <th>Warna</th>
            <th>Tgl. Gambar</th>
            <th>Deadline</th>
            <th>Urgent</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        </thead>

        <tbody>

        @forelse($orders as $order)

            <tr>

                <td>
                    <strong>{{ $order->order_id }}</strong>
                </td>

                <td>
                    {{ $order->guest_name }}
                </td>

                <td>
                    {{ $order->phone }}
                </td>

                <td>

                    @if($order->items)

                        @foreach($order->items as $item)

                            <div>
                                {{ $item['name'] }}
                                × {{ $item['quantity'] }}
                            </div>

                        @endforeach

                    @else
                        -
                    @endif

                </td>

                <td>
                    {{ $order->color ?? '-' }}
                </td>

                <td>
                    {{ $order->image_date?->format('d M Y') ?? '-' }}
                </td>

                <td>
                    {{ $order->deadline?->format('d M Y') ?? '-' }}
                </td>

                <td>

                    @if($order->urgent)

                        <span class="urgent">
                            URGENT
                        </span>

                    @else

                        <span class="normal">
                            Normal
                        </span>

                    @endif

                </td>

                <td>
                    <span class="status">
                        {{ $order->status }}
                    </span>
                </td>

                <td>

                    <div class="actions">

                        <a href="{{ route('orders.show', $order) }}"
                           class="btn btn-info">
                            View
                        </a>

                        <a href="{{ route('orders.edit', $order) }}"
                           class="btn btn-warning">
                            Edit
                        </a>

                        <form action="{{ route('orders.destroy', $order) }}"
                              method="POST"
                              onsubmit="return confirm('Hapus order ini?')">

                            @csrf
                            @method('DELETE')

                            <button class="btn btn-danger">
                                Delete
                            </button>

                        </form>

                    </div>

                </td>

            </tr>

        @empty

            <tr>
                <td colspan="10" style="text-align:center;">
                    Belum ada order.
                </td>
            </tr>

        @endforelse

        </tbody>

    </table>

    <div class="pagination">
        {{ $orders->links() }}
    </div>

</div>

</body>
</html>