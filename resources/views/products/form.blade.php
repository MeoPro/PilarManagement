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
    class="card compact-form product-catalog-form"
    action="{{ isset($product) ? route('products.update', $product) : route('products.store') }}"
    method="POST"
    enctype="multipart/form-data"
>
    @csrf
    @if(isset($product))
        @method('PUT')
    @endif

    <section class="product-form-section">
        <h2>Informasi Produk</h2>
        <div class="product-form-grid">
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
                <label for="product-sku">SKU (opsional)</label>
                <input
                    id="product-sku"
                    type="text"
                    name="sku"
                    value="{{ old('sku', $product->sku ?? '') }}"
                    maxlength="100"
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
                <label for="product-price">Harga PB per unit (Rp)</label>
                <input
                    id="product-price"
                    type="text"
                    inputmode="decimal"
                    name="price"
                    class="price-input"
                    data-money-input
                    value="{{ old('price', isset($product) ? rtrim(rtrim(number_format((float) $product->price, 2, '.', ','), '0'), '.') : '') }}"
                    autocomplete="off"
                    oninput="formatProductPrice(this)"
                    required
                >
            </div>

            @if($showFactorySalePrice)
                <div>
                    <label for="product-factory-sale-price">Harga PF per unit (Rp)</label>
                    <input
                        id="product-factory-sale-price"
                        type="text"
                        inputmode="decimal"
                        name="factory_sale_price"
                        class="price-input"
                        data-money-input
                        value="{{ old('factory_sale_price', isset($product) && $product->factory_sale_price !== null ? rtrim(rtrim(number_format((float) $product->factory_sale_price, 2, '.', ','), '0'), '.') : '') }}"
                        autocomplete="off"
                        oninput="formatProductPrice(this)"
                    >
                    <small>Wajib diisi sebelum produk dicatat sebagai stok PF atau PB.</small>
                </div>
            @endif

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
                <small>Jika produk memiliki dua warna, warna pada order dapat dipilih.</small>
            </div>
        </div>
    </section>

    <section class="product-form-section">
        <h2>Foto Produk dan Gambar Referensi</h2>
        <div class="product-image-grid">
            <div>
                <label for="product-image">Foto Produk</label>
                @if(isset($product) && $product->image)
                    <p>
                        <img
                            class="product-catalog-photo product-catalog-photo-large"
                            src="{{ asset('storage/'.$product->image) }}"
                            alt="Foto produk {{ $product->name }}"
                        >
                    </p>
                @endif
                <input id="product-image" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                <small>Opsional, maksimal 5 MB.</small>
            </div>

            <div>
                <label for="product-mockup">Gambar 1 · Mockup PB</label>
                @if(isset($product) && $product->mockupPaths() !== [])
                    <div class="order-mockup-gallery">
                        @foreach($product->mockupPaths() as $mockupPath)
                            <img
                                class="product-catalog-photo product-catalog-photo-large"
                                src="{{ asset('storage/'.$mockupPath) }}"
                                alt="Mockup PB {{ $product->name }}"
                            >
                        @endforeach
                    </div>
                @endif
                <input id="product-mockup" type="file" name="mockups[]" accept="image/jpeg,image/png,image/webp" multiple>
                <small>Opsional, hingga 10 foto. Gambar PB tersimpan terpisah dari gambar PF.</small>
            </div>

            <div>
                <label for="product-factory-mockup">Gambar 2 · Mockup PF (gambar kedua)</label>
                @if(isset($product) && $product->factory_mockup)
                    <p>
                        <img
                            class="product-catalog-photo product-catalog-photo-large"
                            src="{{ asset('storage/'.$product->factory_mockup) }}"
                            alt="Gambar kerja PF {{ $product->name }}"
                        >
                    </p>
                @else
                    <p class="muted">Belum ada gambar kerja PF.</p>
                @endif
                <input id="product-factory-mockup" type="file" name="factory_mockup" accept="image/jpeg,image/png,image/webp">
                <small>Opsional, maksimal 5 MB. Gambar ini hanya untuk referensi Pilar Factory.</small>
            </div>
        </div>
    </section>

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
