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

        .search-form {
            display: flex;
            gap: 8px;
            max-width: 920px;
            margin: 24px 0;
            align-items: center;
        }

        .search-form input,
        .search-form select {
            flex: 1;
            min-width: 0;
            padding: 9px 11px;
        }

        .deadline-remaining {
            display: block;
            margin-top: 4px;
            color: #555;
            font-size: 12px;
        }

        .deadline-overdue {
            color: #b42318;
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

<a
    href="{{ route('guests.index') }}"
    class="btn"
>
    Daftar Tamu
</a>


<br><br>


<form action="{{ route('orders.index') }}" method="GET" class="search-form">
    <input
        type="search"
        name="search"
        value="{{ $search }}"
        placeholder="Cari ID, nama tamu, telepon, atau produk"
        aria-label="Cari order"
    >

    <select name="sort" aria-label="Urutkan order">
        @foreach($sortOptions as $value => $label)
            <option value="{{ $value }}" @selected($sort === $value)>
                {{ $label }}
            </option>
        @endforeach
    </select>

    <button type="submit" class="btn primary">Search</button>

    @if($search !== '')
        <a href="{{ route('orders.index') }}" class="btn">Reset</a>
    @endif
</form>


<table>

    <thead>

        <tr>

            <th>Order ID</th>

            <th>Tamu</th>

            <th>Sumber Tamu</th>

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
                {{ $order->guest_source ?? '-' }}
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

                @if($order->deadline && $order->status !== 'Selesai')
                    @php($daysRemaining = (int) today()->diffInDays($order->deadline->copy()->startOfDay(), false))
                    @php($deadlineLabel = $daysRemaining > 0 ? $daysRemaining.' hari'.($daysRemaining >= 7 ? ' / '.intdiv($daysRemaining, 7).' minggu' : '').' tersisa' : ($daysRemaining === 0 ? 'Deadline hari ini' : 'Terlambat '.abs($daysRemaining).' hari'))

                    <small class="deadline-remaining {{ $daysRemaining < 0 ? 'deadline-overdue' : '' }}">{{ $deadlineLabel }}</small>
                @endif

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