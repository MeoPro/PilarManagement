<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Categories</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .btn {
            display: inline-block;
            padding: 8px 14px;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: #222;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #000;
        }
    </style>
</head>

<body>

<h1>Categories</h1>

@if(session('success'))
    <div style="margin-bottom:20px;color:green;">
        {{ session('success') }}
    </div>
@endif

<a href="{{ route('categories.create') }}" class="btn btn-primary">
    + Add Category
</a>

<br><br>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Category</th>
            <th>Created</th>
            <th width="180">Action</th>
        </tr>
    </thead>

    <tbody>

    @forelse($categories as $category)

        <tr>
            <td>{{ $loop->iteration }}</td>

            <td>
                {{ $category->name }}
            </td>

            <td>
                {{ $category->created_at->format('d M Y') }}
            </td>

            <td>

                <a
                    href="{{ route('categories.edit', $category) }}"
                    class="btn btn-warning"
                >
                    Edit
                </a>

                <form
                    action="{{ route('categories.destroy', $category) }}"
                    method="POST"
                    style="display:inline;"
                >
                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="btn btn-danger"
                        onclick="return confirm('Hapus category ini?')"
                    >
                        Delete
                    </button>
                </form>

            </td>
        </tr>

    @empty

        <tr>
            <td colspan="4">
                Belum ada category.
            </td>
        </tr>

    @endforelse

    </tbody>
</table>

</body>
</html>