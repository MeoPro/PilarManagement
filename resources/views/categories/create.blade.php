<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Category</title>
</head>

<body style="font-family:Arial;margin:40px;">

<h1>Add Category</h1>

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
    action="{{ route('categories.store') }}"
    method="POST"
>

    @csrf

    <div style="margin-bottom:15px;">

        <label>
            Category Name
        </label>

        <br>

        <input
            type="text"
            name="name"
            value="{{ old('name') }}"
            required
            style="padding:10px;width:300px;"
        >

    </div>

    <button type="submit">
        Save Category
    </button>

    <a href="{{ route('categories.index') }}">
        Cancel
    </a>

</form>

</body>
</html>