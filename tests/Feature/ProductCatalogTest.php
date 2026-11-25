<?php

use App\Models\Category;
use App\Models\Guest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('category management list is ordered alphabetically regardless of name casing', function () {
    $owner = User::factory()->owner()->create();
    Category::create(['name' => 'Zebra']);
    Category::create(['name' => 'kursi']);
    Category::create(['name' => 'Alat']);

    $this->actingAs($owner)
        ->get(route('categories.index'))
        ->assertOk()
        ->assertSeeInOrder(['Alat', 'kursi', 'Zebra']);
});

test('authorized Bali admins can create catalog products and other admins cannot access the catalog', function () {
    Storage::fake('public');
    $category = Category::factory()->create();
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['products'],
    ]);

    $this->actingAs($admin)
        ->post(route('products.store'), [
            'name' => 'Teak Dining Chair',
            'category_id' => $category->id,
            'price' => '125,000',
            'dimensions' => '10 20 30',
            'colors' => ['Teak', 'Natural'],
            'image' => UploadedFile::fake()->createWithContent(
                'chair.gif',
                base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='),
            ),
            'mockups' => [
                UploadedFile::fake()->createWithContent(
                    'chair-mockup-front.gif',
                    base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='),
                ),
                UploadedFile::fake()->createWithContent(
                    'chair-mockup-side.gif',
                    base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='),
                ),
            ],
        ])
        ->assertRedirect(route('products.index'));

    $product = Product::query()->where('name', 'Teak Dining Chair')->firstOrFail();
    expect($product->price)->toBe('125000.00')
        ->and($product->dimensions)->toBe('10x20x30')
        ->and($product->colors)->toBe(['Teak', 'Natural']);
    Storage::disk('public')->assertExists($product->image);
    foreach ($product->mockupPaths() as $mockupPath) {
        Storage::disk('public')->assertExists($mockupPath);
    }
    expect($product->image)->toStartWith('catalog/products/')
        ->and($product->mockupPaths())->toHaveCount(2)
        ->and($product->mockup)->toBe($product->mockupPaths()[0]);

    $this->get(route('products.index'))
        ->assertSeeText('Teak Dining Chair')
        ->assertSeeText('Natural');
    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSeeText('10x20x30')
        ->assertSee(asset('storage/'.$product->image), false)
        ->assertSee(asset('storage/'.$product->mockupPaths()[0]), false)
        ->assertSee(asset('storage/'.$product->mockupPaths()[1]), false)
        ->assertSee(route('products.edit', $product));

    $restrictedAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders'],
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create();

    $this->actingAs($restrictedAdmin)->get(route('products.index'))->assertForbidden();
    $this->actingAs($factoryAdmin)->get(route('products.index'))->assertForbidden();
});

test('product catalog details display the factory mockup saved by PF', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['products'],
    ]);
    $factoryMockupPath = 'catalog/mockups/factory-chair.png';
    $product = Product::factory()->create([
        'factory_mockup' => $factoryMockupPath,
    ]);
    Storage::disk('public')->put($factoryMockupPath, 'PF mockup');

    $this->actingAs($admin)
        ->get(route('products.show', $product))
        ->assertSeeText('Mockup PF (gambar kedua)')
        ->assertSee(asset('storage/'.$factoryMockupPath), false);

    $this->get(route('products.edit', $product))
        ->assertSeeText('Mockup PF (gambar kedua)')
        ->assertSee(asset('storage/'.$factoryMockupPath), false);
});

test('catalog edit prices omit trailing zero decimals for whole rupiah values', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['products'],
    ]);
    $product = Product::factory()->create([
        'name' => 'Formatted Price Chair',
        'price' => 125000,
    ]);

    $this->actingAs($admin)
        ->get(route('products.edit', $product))
        ->assertSee('value="125,000"', false)
        ->assertDontSee('125,000.00');
});

test('catalog products accept an optional SKU that can be edited', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['products'],
    ]);
    $category = Category::factory()->create();

    $this->actingAs($admin)
        ->post(route('products.store'), [
            'name' => 'SKU Catalog Chair',
            'sku' => 'CHAIR-001',
            'category_id' => $category->id,
            'price' => 125000,
            'dimensions' => '10x20x30',
        ])
        ->assertRedirect(route('products.index'));

    $product = Product::query()->where('name', 'SKU Catalog Chair')->firstOrFail();
    expect($product->sku)->toBe('CHAIR-001');

    $this->get(route('products.edit', $product))
        ->assertSee('name="sku"', false)
        ->assertSee('value="CHAIR-001"', false);

    $this->put(route('products.update', $product), [
        'name' => $product->name,
        'sku' => 'CHAIR-002',
        'category_id' => $category->id,
        'price' => 125000,
        'dimensions' => '10x20x30',
    ])->assertRedirect(route('products.index'));

    expect($product->fresh()->sku)->toBe('CHAIR-002');

    $this->get(route('products.index'))
        ->assertSeeInOrder(['<th>SKU</th>', '<th>Foto</th>', '<td>CHAIR-002</td>'], false);
});

test('orders use the selected catalog SKU by default and allow an optional override', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $product = Product::factory()
        ->for($category)
        ->create([
            'name' => 'SKU Dining Chair',
            'sku' => 'CHAIR-CATALOG-001',
            'dimensions' => '10x20x30',
        ]);
    $guest = Guest::create(['name' => 'SKU Order Guest']);

    $this->actingAs($admin)
        ->get(route('orders.create'))
        ->assertSee('SKU (opsional)', false)
        ->assertSee('CHAIR-CATALOG-001')
        ->assertSee('name="items[${productIndex}][sku]"', false);

    $this->post(route('orders.store'), [
        'order_id' => 'SKU-AUTO-FILL-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'items' => [[
            'product' => $product->name,
            'product_id' => $product->id,
            'category_id' => $category->id,
            'dimensions' => '10x20x30',
            'qty' => 1,
            'price' => 125000,
            'source' => 'Order',
            'status' => 'None',
        ]],
    ])->assertRedirect(route('orders.index'));

    $order = Order::query()->where('order_id', 'SKU-AUTO-FILL-ORDER')->firstOrFail();
    $item = $order->items()->firstOrFail();
    expect($item->sku)->toBe('CHAIR-CATALOG-001');

    $this->get(route('orders.edit', $order))
        ->assertSee('name="items[0][sku]"', false)
        ->assertSee('value="CHAIR-CATALOG-001"', false);

    $this->put(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'items' => [[
            'id' => $item->id,
            'product' => $product->name,
            'product_id' => $product->id,
            'sku' => 'CHAIR-ORDER-OVERRIDE',
            'category_id' => $category->id,
            'dimensions' => '10x20x30',
            'qty' => 1,
            'price' => 125000,
            'source' => 'Order',
            'status' => 'None',
        ]],
    ])->assertRedirect(route('orders.index'));

    expect($item->fresh()->sku)->toBe('CHAIR-ORDER-OVERRIDE')
        ->and($product->fresh()->sku)->toBe('CHAIR-CATALOG-001');
});

test('order product selectors include historical products and colors alongside catalog variants', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    Product::factory()
        ->for($category)
        ->create([
            'name' => 'Color Suggestion Chair',
            'colors' => ['Ivory'],
        ]);
    $guest = Guest::create(['name' => 'Color Suggestion Guest']);
    $order = Order::create([
        'order_id' => 'COLOR-SUGGESTION-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 100,
        'status' => 'None',
    ]);
    $order->items()->create([
        'product' => 'Color Suggestion Chair',
        'category_id' => $category->id,
        'dimensions' => '10x20x30',
        'qty' => 1,
        'price' => 100,
        'color' => 'Oak',
        'source' => 'Order',
        'status' => 'None',
    ]);
    $order->items()->create([
        'product' => 'Color Suggestion Chair',
        'category_id' => $category->id,
        'dimensions' => null,
        'qty' => 1,
        'price' => 100,
        'color' => 'Oak',
        'source' => 'Order',
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->get(route('orders.create'))
        ->assertSee('role="combobox"', false)
        ->assertSee('"colors":["Ivory"]', false)
        ->assertSee('"color":"Oak"', false)
        ->assertSee('"dimensions":"10x20x30"', false)
        ->assertSee('matchingCatalogProduct.dimensions || matchingProduct?.dimensions', false)
        ->assertSee('product-suggestion-color', false);

    $editResponse = $this->get(route('orders.edit', $order));
    $editResponse
        ->assertSee('role="combobox"', false)
        ->assertSee('"colors":["Ivory"]', false)
        ->assertSee('"color":"Oak"', false)
        ->assertSee('"dimensions":"10x20x30"', false)
        ->assertSee('matchingCatalogProduct.dimensions || matchingProduct?.dimensions', false)
        ->assertSee('product-suggestion-color', false)
        ->assertSee('name="items[${productIndex}][product]"', false);

    expect(substr_count($editResponse->getContent(), '<div class="product-selector">'))->toBe(3);

    foreach (range(0, 200) as $index) {
        $order->items()->create([
            'product' => "Historical Product {$index}",
            'category_id' => $category->id,
            'dimensions' => '10x20x30',
            'qty' => 1,
            'price' => 100,
            'source' => 'Order',
            'status' => 'None',
        ]);
    }

    $this->get(route('orders.create'))
        ->assertSee('"name":"Historical Product 0"', false);
});

test('changing a catalog product color on an order creates a separate catalog variant', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $product = Product::factory()
        ->for($category)
        ->create([
            'name' => 'Color Variant Chair',
            'price' => 150000,
            'dimensions' => null,
            'colors' => ['Oak'],
        ]);
    $guest = Guest::create(['name' => 'Color Variant Guest']);
    $order = Order::create([
        'order_id' => 'COLOR-VARIANT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'total_order' => 150000,
        'status' => 'None',
    ]);
    $item = $order->items()->create([
        'product' => $product->name,
        'product_id' => $product->id,
        'category_id' => $category->id,
        'dimensions' => '10x20x30',
        'qty' => 1,
        'price' => 150000,
        'color' => 'Oak',
        'source' => 'Order',
        'status' => 'None',
        'factory_status' => 'None',
    ]);

    $this->actingAs($admin)
        ->get(route('orders.edit', $order))
        ->assertSee('name="items[0][color]"', false);

    $this->put(route('orders.update', $order), [
        'order_id' => $order->order_id,
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'paid_amount' => 0,
        'items' => [[
            'id' => $item->id,
            'product' => $product->name,
            'product_id' => $product->id,
            'category_id' => $category->id,
            'dimensions' => '',
            'qty' => 1,
            'price' => 150000,
            'color' => 'Ivory',
            'source' => 'Order',
            'status' => 'None',
        ]],
    ])->assertRedirect(route('orders.index'));

    $variant = Product::query()
        ->where('name', $product->name)
        ->where('id', '!=', $product->id)
        ->firstOrFail();

    expect(Product::where('name', $product->name)->count())->toBe(2)
        ->and($product->fresh()->colors)->toBe(['Oak'])
        ->and($variant->colors)->toBe(['Ivory'])
        ->and($variant->dimensions)->toBe('10x20x30')
        ->and($variant->image)->toBeNull()
        ->and($item->fresh()->product_id)->toBe($variant->id)
        ->and($item->fresh()->dimensions)->toBe('10x20x30')
        ->and($item->fresh()->color)->toBe('Ivory')
        ->and($item->fresh()->status)->toBe('None');
});

test('order mockups are saved to catalog while PF remains new until its own data is ready', function () {
    Storage::fake('public');
    Storage::disk('public')->put('catalog/products/reusable-product.jpg', 'product photo');
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create([
        'name' => 'Reusable Mockup Chair',
        'price' => 150000,
        'dimensions' => '10x20x30',
        'colors' => ['Natural'],
        'image' => 'catalog/products/reusable-product.jpg',
    ]);

    $this->actingAs($admin)
        ->post(route('orders.store'), [
            'order_id' => 'FIRST-MOCKUP-ORDER',
            'guest_name' => 'Reusable Mockup Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'items' => [[
                'product' => $product->name,
                'product_id' => $product->id,
                'dimensions' => '10x20x30',
                'qty' => 1,
                'price' => 150000,
                'color' => 'Natural',
                'source' => 'Order',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $firstOrder = Order::query()->where('order_id', 'FIRST-MOCKUP-ORDER')->firstOrFail();
    $firstItem = $firstOrder->items()->firstOrFail();
    $firstProductImagePath = $firstItem->image;

    expect($firstItem->status)->toBe('None')
        ->and($firstItem->factory_status)->toBe('None')
        ->and($firstItem->status_evidence_images)->toBe([]);
    Storage::disk('public')->assertExists($firstProductImagePath);

    $this->put(route('orders.update', $firstOrder), [
        'order_id' => $firstOrder->order_id,
        'guest_name' => 'Reusable Mockup Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'paid_amount' => 0,
        'items' => [[
            'id' => $firstItem->id,
            'product' => $product->name,
            'product_id' => $product->id,
            'dimensions' => '10x20x30',
            'qty' => 1,
            'price' => 150000,
            'color' => 'Natural',
            'source' => 'Order',
            'status' => 'None',
            'status_images' => [
                UploadedFile::fake()->create('catalog-mockup.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('catalog-mockup-side.jpg', 100, 'image/jpeg'),
            ],
        ]],
    ])
        ->assertRedirect(route('orders.index'));

    $catalogMockupPaths = $product->fresh()->mockupPaths();
    expect($catalogMockupPaths)->toHaveCount(2);
    expect($catalogMockupPaths[0])->toBeString()->toStartWith('catalog/mockups/');
    expect($product->fresh()->image)->toBe('catalog/products/reusable-product.jpg')
        ->and($firstItem->fresh()->image)->toBe($firstProductImagePath)
        ->and($firstItem->fresh()->status)->toBe('Produksi')
        ->and($firstItem->fresh()->factory_status)->toBe('None');
    foreach ($catalogMockupPaths as $catalogMockupPath) {
        Storage::disk('public')->assertExists($catalogMockupPath);
    }

    $this->get(route('products.show', $product))
        ->assertSee(asset('storage/catalog/products/reusable-product.jpg'), false)
        ->assertSee(asset('storage/'.$catalogMockupPaths[0]), false)
        ->assertSee(asset('storage/'.$catalogMockupPaths[1]), false);

    $this->post(route('orders.store'), [
        'order_id' => 'SECOND-MOCKUP-ORDER',
        'guest_name' => 'Reusable Mockup Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'items' => [[
            'product' => $product->name,
            'product_id' => $product->id,
            'dimensions' => '10x20x30',
            'qty' => 1,
            'price' => 150000,
            'color' => 'Natural',
            'source' => 'Order',
        ]],
    ])->assertRedirect(route('orders.index'));

    $secondItem = Order::query()
        ->where('order_id', 'SECOND-MOCKUP-ORDER')
        ->firstOrFail()
        ->items()
        ->firstOrFail();

    expect($secondItem->status)->toBe('Produksi')
        ->and($secondItem->factory_status)->toBe('None')
        ->and($secondItem->status_evidence_images)->toHaveCount(2)
        ->and($secondItem->image)->not->toBe($catalogMockupPaths[0]);
    foreach ($secondItem->status_evidence_images as $mockupPath) {
        Storage::disk('public')->assertExists($mockupPath);
    }
    Storage::disk('public')->assertExists($secondItem->image);
});

test('catalog product details and photos are copied into new order items while colors follow product choices', function () {
    Storage::fake('public');
    Storage::disk('public')->put('catalog/products/chair.jpg', 'catalog image');

    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $singleColorProduct = Product::factory()
        ->for($category)
        ->create([
            'name' => 'Catalog Chair',
            'price' => 175000,
            'dimensions' => '10x20x30',
            'colors' => ['White'],
            'image' => 'catalog/products/chair.jpg',
        ]);
    $multiColorProduct = Product::factory()
        ->for($category)
        ->create([
            'name' => 'Catalog Table',
            'price' => 425000,
            'dimensions' => '20x30x40',
            'colors' => ['Black', 'Natural'],
        ]);

    $this->actingAs($admin)
        ->get(route('orders.create'))
        ->assertSeeText('Catalog Chair')
        ->assertSee('data-furniture-dimensions', false)
        ->assertDontSee('name="items[${productIndex}][status_images][]"', false)
        ->assertDontSee('name="items[${productIndex}][image]"', false);

    $this->post(route('orders.store'), [
        'order_id' => 'CATALOG-PRODUCT-ORDER',
        'guest_name' => 'Catalog Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'status' => 'None',
        'paid_amount' => '0',
        'items' => [
            [
                'product' => $singleColorProduct->name,
                'product_id' => $singleColorProduct->id,
                'dimensions' => '10x20x30',
                'qty' => 1,
                'price' => '175000',
                'color' => '',
                'source' => 'Order',
                'status' => 'None',
            ],
            [
                'product' => $multiColorProduct->name,
                'product_id' => $multiColorProduct->id,
                'dimensions' => '20x30x40',
                'qty' => 1,
                'price' => '425000',
                'color' => '',
                'source' => 'Order',
                'status' => 'None',
            ],
            [
                'product' => $singleColorProduct->name,
                'product_id' => $singleColorProduct->id,
                'dimensions' => '10x20x30',
                'qty' => 1,
                'price' => '175000',
                'color' => 'Ivory',
                'source' => 'Order',
                'status' => 'None',
            ],
        ],
    ])->assertRedirect(route('orders.index'));

    $order = Order::query()
        ->with('items')
        ->where('order_id', 'CATALOG-PRODUCT-ORDER')
        ->firstOrFail();
    $singleColorItem = $order->items->firstWhere('product_id', $singleColorProduct->id);
    $multiColorItem = $order->items->firstWhere('product_id', $multiColorProduct->id);
    $customColorItem = $order->items->firstWhere('color', 'Ivory');

    expect($singleColorItem->category_id)->toBe($category->id)
        ->and($singleColorItem->color)->toBe('White')
        ->and($singleColorItem->dimensions)->toBe('10x20x30')
        ->and($singleColorItem->image)->not->toBe($singleColorProduct->image)
        ->and($singleColorItem->status)->toBe('None')
        ->and($singleColorItem->status_evidence_images)->toBe([])
        ->and($multiColorItem->color)->toBeNull()
        ->and($customColorItem->color)->toBe('Ivory');
    Storage::disk('public')->assertExists($singleColorItem->image);

    $this->get(route('orders.show', $order))
        ->assertSeeText('10x20x30');
    $this->get(route('orders.edit', $order))
        ->assertSee('10x20x30')
        ->assertSee('name="items[0][status_images][]"', false)
        ->assertDontSee('name="items[0][image]"', false);
});

test('new order products are saved to the catalog with normalized dimensions', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    $this->actingAs($admin)
        ->post(route('orders.store'), [
            'order_id' => 'AUTO-CATALOG-ORDER',
            'guest_name' => 'Auto Catalog Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'items' => [[
                'product' => 'Custom Dining Table',
                'sku' => 'TABLE-CUSTOM-001',
                'category_id' => $category->id,
                'dimensions' => '10 20 30',
                'qty' => 1,
                'price' => '250000',
                'color' => 'Natural',
                'source' => 'Order',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    $product = Product::query()->where('name', 'Custom Dining Table')->firstOrFail();
    $item = Order::query()
        ->where('order_id', 'AUTO-CATALOG-ORDER')
        ->firstOrFail()
        ->items()
        ->firstOrFail();

    expect($product->dimensions)->toBe('10x20x30')
        ->and($product->sku)->toBe('TABLE-CUSTOM-001')
        ->and($product->price)->toBe('250000.00')
        ->and($product->colors)->toBe(['Natural'])
        ->and($item->product_id)->toBe($product->id)
        ->and($item->sku)->toBe('TABLE-CUSTOM-001')
        ->and($item->dimensions)->toBe('10x20x30');
});

test('new orders reject dimensions without exactly three numeric values', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('orders.create'))
        ->post(route('orders.store'), [
            'order_id' => 'INVALID-DIMENSIONS-ORDER',
            'guest_name' => 'Invalid Dimensions Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'items' => [[
                'product' => 'Invalid Dimensions Table',
                'dimensions' => '10x20',
                'qty' => 1,
                'price' => '250000',
                'source' => 'Order',
            ]],
        ])
        ->assertSessionHasErrors('items.0.dimensions');

    $this->assertDatabaseMissing('orders', [
        'order_id' => 'INVALID-DIMENSIONS-ORDER',
    ]);
    $this->assertDatabaseMissing('products', [
        'name' => 'Invalid Dimensions Table',
    ]);
});

test('new color variants keep the lowest price without changing the original catalog colors', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create([
        'name' => 'Minimum Price Chair',
        'price' => 200000,
        'dimensions' => '10x20x30',
        'colors' => ['Natural'],
    ]);

    $this->actingAs($admin)
        ->post(route('orders.store'), [
            'order_id' => 'HIGHER-CATALOG-PRICE',
            'guest_name' => 'Higher Price Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'status' => 'None',
            'paid_amount' => '0',
            'items' => [[
                'product' => $product->name,
                'product_id' => $product->id,
                'dimensions' => '10x20x30',
                'qty' => 1,
                'price' => '250000',
                'color' => 'Ivory',
                'source' => 'Order',
                'status' => 'None',
            ]],
        ])
        ->assertRedirect(route('orders.index'));

    expect($product->fresh()->price)->toBe('200000.00');

    $this->post(route('orders.store'), [
        'order_id' => 'LOWER-CATALOG-PRICE',
        'guest_name' => 'Lower Price Guest',
        'guest_source' => 'Lokal',
        'order_type' => 'Bali',
        'status' => 'None',
        'paid_amount' => '0',
        'items' => [[
            'product' => $product->name,
            'product_id' => $product->id,
            'dimensions' => '10x20x30',
            'qty' => 1,
            'price' => '150000',
            'color' => 'Ivory',
            'source' => 'Order',
            'status' => 'None',
        ]],
    ])
        ->assertRedirect(route('orders.index'));

    $orderItem = Order::query()
        ->where('order_id', 'LOWER-CATALOG-PRICE')
        ->firstOrFail()
        ->items()
        ->firstOrFail();

    $variant = Product::query()
        ->where('name', $product->name)
        ->where('id', '!=', $product->id)
        ->firstOrFail();

    expect($product->fresh()->price)->toBe('200000.00')
        ->and($product->fresh()->colors)->toBe(['Natural'])
        ->and($variant->price)->toBe('150000.00')
        ->and($variant->colors)->toBe(['Ivory'])
        ->and($orderItem->product_id)->toBe($variant->id)
        ->and($orderItem->color)->toBe('Ivory');
});

test('deleting a catalog product preserves its order photo snapshot', function () {
    Storage::fake('public');
    Storage::disk('public')->put('catalog/products/table.jpg', 'catalog image');
    Storage::disk('public')->put('orders/products/order-table.jpg', 'order image');

    $owner = User::factory()->owner()->create();
    $category = Category::factory()->create();
    $product = Product::factory()
        ->for($category)
        ->create(['image' => 'catalog/products/table.jpg']);
    $guest = Guest::create(['name' => 'Product Deletion Guest']);
    $order = Order::create([
        'order_id' => 'PRODUCT-DELETION-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => $product->price,
        'status' => 'None',
    ]);
    $orderItem = $order->items()->create([
        'product' => $product->name,
        'product_id' => $product->id,
        'category_id' => $category->id,
        'qty' => 1,
        'price' => $product->price,
        'source' => 'Order',
        'status' => 'None',
        'image' => 'orders/products/order-table.jpg',
    ]);

    $this->actingAs($owner)
        ->delete(route('products.destroy', $product))
        ->assertRedirect(route('products.index'));

    $this->assertDatabaseMissing('products', ['id' => $product->id]);
    $this->assertDatabaseHas('order_items', [
        'id' => $orderItem->id,
        'product_id' => null,
        'image' => 'orders/products/order-table.jpg',
    ]);
    Storage::disk('public')->assertMissing('catalog/products/table.jpg');
    Storage::disk('public')->assertExists('orders/products/order-table.jpg');
});

test('updating a catalog product preserves its photo when no replacement is uploaded', function () {
    Storage::fake('public');
    Storage::disk('public')->put('catalog/products/sofa.jpg', 'catalog image');

    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['products'],
    ]);
    $category = Category::factory()->create();
    $product = Product::factory()
        ->for($category)
        ->create([
            'name' => 'Original Sofa',
            'image' => 'catalog/products/sofa.jpg',
        ]);

    $this->actingAs($admin)
        ->put(route('products.update', $product), [
            'name' => 'Updated Sofa',
            'category_id' => $category->id,
            'price' => '250,000',
            'dimensions' => '12 24 36',
            'colors' => ['Cream', 'Gray'],
        ])
        ->assertRedirect(route('products.index'));

    expect($product->fresh()->name)->toBe('Updated Sofa')
        ->and($product->fresh()->price)->toBe('250000.00')
        ->and($product->fresh()->dimensions)->toBe('12x24x36')
        ->and($product->fresh()->image)->toBe('catalog/products/sofa.jpg');
    Storage::disk('public')->assertExists('catalog/products/sofa.jpg');
});

test('rejects a catalog product id paired with a different order product name', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['name' => 'Catalog Product']);

    $this->actingAs($admin)
        ->post(route('orders.store'), [
            'order_id' => 'MISMATCHED-CATALOG-PRODUCT',
            'guest_name' => 'Catalog Mismatch Guest',
            'guest_source' => 'Lokal',
            'order_type' => 'Bali',
            'status' => 'None',
            'paid_amount' => '0',
            'items' => [
                [
                    'product' => 'Different Product',
                    'product_id' => $product->id,
                    'dimensions' => '10x10x10',
                    'qty' => 1,
                    'price' => '100000',
                    'source' => 'Order',
                    'status' => 'None',
                ],
            ],
        ])
        ->assertInvalid([
            'items.0.product' => 'Nama produk tidak cocok dengan produk katalog yang dipilih.',
        ]);

    $this->assertDatabaseMissing('orders', [
        'order_id' => 'MISMATCHED-CATALOG-PRODUCT',
    ]);
});

test('escapes untrusted catalog names and colors in the product list', function () {
    $owner = User::factory()->owner()->create();
    Product::factory()->create([
        'name' => '<script>alert(1)</script>',
        'colors' => ['<img src=x onerror=alert(1)>'],
    ]);

    $this->actingAs($owner)
        ->get(route('products.index'))
        ->assertSee('&lt;script&gt;', false)
        ->assertSee('&lt;img', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('PB admins cannot view or change PF catalog prices while owners can', function () {
    $category = Category::factory()->create();
    $product = Product::factory()
        ->for($category)
        ->create([
            'price' => 125000,
            'factory_sale_price' => 40000,
        ]);
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['products'],
    ]);

    $this->actingAs($admin)
        ->get(route('products.edit', $product))
        ->assertSeeText('Harga PB per unit')
        ->assertDontSeeText('Harga PF per unit')
        ->assertDontSee('name="factory_sale_price"', false);

    $this->put(route('products.update', $product), [
        'name' => $product->name,
        'category_id' => $category->id,
        'price' => 125000,
        'factory_sale_price' => 1,
        'dimensions' => '10x20x30',
    ])->assertRedirect(route('products.index'));

    expect((float) $product->fresh()->factory_sale_price)->toBe(40000.0);
    $this->get(route('products.show', $product))
        ->assertSeeText('Harga PB per unit')
        ->assertDontSeeText('Harga PF per unit');

    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)
        ->get(route('products.edit', $product))
        ->assertSeeText('Harga PF per unit')
        ->assertSee('value="40,000"', false);
});

test('PB order forms receive only a factory-price availability flag', function () {
    $admin = User::factory()->admin()->create(['accessible_menus' => ['orders']]);
    $category = Category::factory()->create();
    Product::factory()
        ->for($category)
        ->create([
            'name' => 'Private Factory Price Chair',
            'price' => 125000,
            'factory_sale_price' => 40000,
        ]);

    $response = $this->actingAs($admin)->get(route('orders.create'));

    $response->assertSee('has_factory_sale_price', false);
    expect($response->getContent())->not->toContain('"factory_sale_price":40000');
});
