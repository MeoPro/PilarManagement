<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Tamu</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            color: #222;
        }

        .actions {
            display: flex;
            gap: 8px;
            margin: 20px 0;
        }

        .btn {
            display: inline-block;
            padding: 8px 12px;
            border: 0;
            border-radius: 5px;
            background: #222;
            color: white;
            cursor: pointer;
            text-decoration: none;
        }

        .search-form {
            display: flex;
            gap: 8px;
            max-width: 860px;
            margin: 24px 0;
        }

        .search-form input,
        .search-form select {
            flex: 1;
            min-width: 0;
            padding: 9px 11px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #f5f5f5;
        }

        @media (max-width: 700px) {
            body {
                margin: 16px;
            }

            .table-wrapper {
                overflow-x: auto;
            }

            table {
                min-width: 650px;
            }
        }
    </style>
</head>

<body>
    <h1>Daftar Tamu</h1>

    <div class="actions">
        <a href="{{ route('orders.index') }}" class="btn">Kembali ke Order</a>
        <a href="{{ route('orders.create') }}" class="btn">+ Create Order</a>
    </div>

    <form action="{{ route('guests.index') }}" method="GET" class="search-form">
        <input
            type="search"
            name="search"
            value="{{ $search }}"
            placeholder="Cari nama tamu, telepon, atau Order ID"
            aria-label="Cari tamu"
        >
        <select name="sort" aria-label="Urutkan tamu">
            @foreach($sortOptions as $value => $label)
                <option value="{{ $value }}" @selected($sort === $value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        <button type="submit" class="btn">Cari</button>
        @if($search !== '' || $sort !== 'alphabetical')
            <a href="{{ route('guests.index') }}" class="btn">Reset</a>
        @endif
    </form>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID Tamu</th>
                    <th>Tamu</th>
                    <th>No. Telp</th>
                    <th>Order ID</th>
                    <th>Jumlah Order</th>
                    <th>Total Order</th>
                </tr>
            </thead>
            <tbody>
                @forelse($guests as $guest)
                    <tr>
                        <td>{{ $guest->id }}</td>
                        <td>{{ $guest->name }}</td>
                        <td>
                            @forelse($guest->phones as $guestPhone)
                                {{ $guestPhone->phone }}@if(!$loop->last), @endif
                            @empty
                                -
                            @endforelse
                        </td>
                        <td>
                            @foreach($guest->orders as $order)
                                <a href="{{ route('orders.show', $order) }}">{{ $order->order_id }}</a>@if(!$loop->last), @endif
                            @endforeach
                        </td>
                        <td>{{ $guest->orders_count }}</td>
                        <td>Rp {{ number_format($guest->orders_sum_total_order ?? 0, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Belum ada data tamu.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $guests->links() }}
</body>

</html>
