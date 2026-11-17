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
        ])
        ->assertRedirect(route('products.index'));

    $product = Product::query()->where('name', 'Teak Dining Chair')->firstOrFail();
    expect($product->price)->toBe('125000.00')
        ->and($product->dimensions)->toBe('10x20x30')
        ->and($product->colors)->toBe(['Teak', 'Natural']);
    Storage::disk('public')->assertExists($product->image);

    $this->get(route('products.index'))
        ->assertSeeText('Teak Dining Chair')
        ->assertSeeText('Natural');
    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSeeText('10x20x30')
        ->assertSee(route('products.edit', $product));

    $restrictedAdmin = User::factory()->admin()->create([
        'accessible_menus' => ['orders'],
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create();

    $this->actingAs($restrictedAdmin)->get(route('products.index'))->assertForbidden();
    $this->actingAs($factoryAdmin)->get(route('products.index'))->assertForbidden();
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
        ->and($multiColorItem->color)->toBeNull()
        ->and($customColorItem->color)->toBe('Ivory');
    Storage::disk('public')->assertExists($singleColorItem->image);

    $this->get(route('orders.show', $order))
        ->assertSeeText('10x20x30');
    $this->get(route('orders.edit', $order))
        ->assertSee('name="items[0][status_images][]"', false)
        ->assertSee('10x20x30')
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
        ->and($product->price)->toBe('250000.00')
        ->and($product->colors)->toBe(['Natural'])
        ->and($item->product_id)->toBe($product->id)
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

test('orders keep the lowest catalog price without changing the catalog color choices', function () {
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

    expect($product->fresh()->price)->toBe('150000.00')
        ->and($product->fresh()->colors)->toBe(['Natural'])
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
