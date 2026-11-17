@if($errors->any())
    <div class="field-error" role="alert">
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    class="card compact-form"
    action="{{ isset($product) ? route('products.update', $product) : route('products.store') }}"
    method="POST"
    enctype="multipart/form-data"
>
    @csrf
    @if(isset($product))
        @method('PUT')
    @endif

    <div>
        <label for="product-name">Nama Produk</label>
        <input
            id="product-name"
            type="text"
            name="name"
            value="{{ old('name', $product->name ?? '') }}"
            maxlength="255"
            required
        >
    </div>

    <div>
        <label for="product-category">Kategori</label>
        <select id="product-category" name="category_id" required>
            <option value="">Pilih kategori</option>
            @foreach($categories as $category)
                <option
                    value="{{ $category->id }}"
                    @selected((string) old('category_id', $product->category_id ?? '') === (string) $category->id)
                >
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="product-price">Harga (Rp)</label>
        <input
            id="product-price"
            type="text"
            inputmode="decimal"
            name="price"
            class="price-input"
            data-money-input
            value="{{ old('price', isset($product) ? number_format((float) $product->price, 2, '.', ',') : '') }}"
            autocomplete="off"
            oninput="formatProductPrice(this)"
            required
        >
    </div>

    <div>
        <label for="product-dimensions">Ukuran (P x L x T)</label>
        <input
            id="product-dimensions"
            type="text"
            name="dimensions"
            value="{{ old('dimensions', $product->dimensions ?? '') }}"
            data-furniture-dimensions
            placeholder="Contoh: 10 10 10"
            maxlength="100"
            required
        >
    </div>

    <div>
        <label for="product-color-one">Warna pertama (opsional)</label>
        <input
            id="product-color-one"
            type="text"
            name="colors[]"
            list="catalog-color-options"
            value="{{ old('colors.0', data_get($product ?? null, 'colors.0', '')) }}"
            maxlength="255"
        >
    </div>

    <div>
        <label for="product-color-two">Warna kedua (opsional)</label>
        <input
            id="product-color-two"
            type="text"
            name="colors[]"
            list="catalog-color-options"
            value="{{ old('colors.1', data_get($product ?? null, 'colors.1', '')) }}"
            maxlength="255"
        >
        <small>Jika produk memiliki dua warna, warna pada order akan dikosongkan agar dapat dipilih.</small>
    </div>

    <div>
        <label for="product-image">Foto Produk</label>
        @if(isset($product) && $product->image)
            <p>
                <img
                    class="product-catalog-photo product-catalog-photo-large"
                    src="{{ asset('storage/'.$product->image) }}"
                    alt="Foto {{ $product->name }}"
                >
            </p>
        @endif
        <input id="product-image" type="file" name="image" accept="image/*">
        <small>Opsional, maksimal 5 MB.</small>
    </div>

    <datalist id="catalog-color-options">
        @foreach($colors as $color)
            <option value="{{ $color }}"></option>
        @endforeach
    </datalist>

    <div class="employee-form-actions">
        <button class="btn-primary" type="submit">
            {{ isset($product) ? 'Simpan Perubahan' : 'Simpan Produk' }}
        </button>
        <a class="btn" href="{{ route('products.index') }}">Batal</a>
    </div>
</form>

<script>
    function formatProductPrice(input)
    {
        const parts = input.value
            .replace(/,/g, '')
            .replace(/[^\d.]/g, '')
            .split('.');
        const integerPart = (parts.shift() ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const decimalPart = parts.join('').slice(0, 2);

        input.value = parts.length > 0
            ? `${integerPart}.${decimalPart}`
            : integerPart;
    }
</script>
