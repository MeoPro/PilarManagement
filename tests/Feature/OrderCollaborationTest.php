<?php

use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderCommentRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('pilar bali comments can be read and replied to by pilar factory', function () {
    $baliAdmin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Chat Guest']);
    $order = Order::create([
        'order_id' => 'CHAT-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100000,
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Chat Order Product',
        'qty' => 1,
        'price' => 100000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
    ]);

    $this->actingAs($baliAdmin)
        ->post(route('orders.comments.store', $order), [
            'body' => 'Please confirm the finish.',
        ])
        ->assertRedirect(route('orders.show', $order));

    $comment = OrderComment::where('order_id', $order->id)->firstOrFail();
    $this->actingAs($baliAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('✓ Terkirim', false);

    $this->assertDatabaseHas('order_comment_recipients', [
        'order_comment_id' => $comment->id,
        'user_id' => $factoryAdmin->id,
        'read_at' => null,
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('Please confirm the finish.')
        ->assertSee('✓✓ Dibaca', false);

    expect(OrderCommentRecipient::query()
        ->where('order_comment_id', $comment->id)
        ->where('user_id', $factoryAdmin->id)
        ->value('read_at'))->not->toBeNull();

    $this->post(route('orders.comments.store', $order), [
        'parent_id' => $comment->id,
        'body' => 'Confirmed by production.',
    ])->assertRedirect(route('orders.show', $order));

    $this->assertDatabaseHas('order_comments', [
        'order_id' => $order->id,
        'parent_id' => $comment->id,
        'user_id' => $factoryAdmin->id,
        'business_unit' => User::UNIT_FACTORY,
        'body' => 'Confirmed by production.',
    ]);

    $factoryReply = OrderComment::query()
        ->where('order_id', $order->id)
        ->where('body', 'Confirmed by production.')
        ->firstOrFail();

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('✓ Terkirim', false);

    $this->actingAs($baliAdmin)
        ->get(route('orders.show', $order))
        ->assertSee('Confirmed by production.')
        ->assertSee('✓✓ Dibaca', false);

    $this->actingAs($baliAdmin)
        ->post(route('orders.comments.store', $order), [
            'parent_id' => $factoryReply->id,
            'body' => 'PB confirms the update.',
        ])
        ->assertRedirect(route('orders.show', $order));

    $this->assertDatabaseHas('order_comments', [
        'order_id' => $order->id,
        'parent_id' => $factoryReply->id,
        'user_id' => $baliAdmin->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'PB confirms the update.',
    ]);
});

test('comment and reply requests return rendered fragments without redirecting the page', function () {
    $baliAdmin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $order = Order::create([
        'order_id' => 'AJAX-COMMENT-ORDER',
        'guest_name' => 'Ajax Comment Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);

    $commentResponse = $this->actingAs($baliAdmin)
        ->postJson(route('orders.comments.store', $order), [
            'body' => 'Comment without a page refresh.',
        ])
        ->assertOk()
        ->assertJsonPath('comment.parent_id', null)
        ->assertJsonPath('message', 'Komentar berhasil dikirim.');

    $commentId = $commentResponse->json('comment.id');
    expect($commentResponse->json('html'))->toContain('Comment without a page refresh.');

    $replyResponse = $this->actingAs($factoryAdmin)
        ->postJson(route('orders.comments.store', $order), [
            'parent_id' => $commentId,
            'body' => 'Reply without a page refresh.',
        ])
        ->assertOk()
        ->assertJsonPath('comment.parent_id', $commentId);

    expect($replyResponse->json('html'))->toContain('Reply without a page refresh.');
    $this->assertDatabaseHas('order_comments', [
        'order_id' => $order->id,
        'parent_id' => $commentId,
        'body' => 'Reply without a page refresh.',
    ]);
});

test('invalid JSON comment requests return validation errors without saving a comment', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::create([
        'order_id' => 'INVALID-AJAX-COMMENT-ORDER',
        'guest_name' => 'Invalid Ajax Comment Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->postJson(route('orders.comments.store', $order), [
            'body' => '',
        ])
        ->assertInvalid(['body']);

    $this->assertDatabaseCount('order_comments', 0);
});

test('unread order comments create a reminder after two days and are reminded once', function () {
    $this->travelTo('2026-03-10 09:00:00');
    $baliAdmin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Reminder Guest']);
    $order = Order::create([
        'order_id' => 'REMINDER-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100000,
        'status' => 'None',
    ]);

    $this->actingAs($baliAdmin)
        ->post(route('orders.comments.store', $order), [
            'body' => 'Waiting for an update.',
        ])
        ->assertRedirect(route('orders.show', $order));

    $this->travelTo('2026-03-12 09:00:01');
    $this->actingAs($factoryAdmin)
        ->getJson(route('order-comments.reminders'))
        ->assertJsonPath('reminders.0.order_id', 'REMINDER-ORDER')
        ->assertJsonPath('reminders.0.body', 'Waiting for an update.');

    $this->getJson(route('order-comments.reminders'))
        ->assertExactJson(['reminders' => []]);

    expect(OrderCommentRecipient::query()
        ->where('user_id', $factoryAdmin->id)
        ->value('reminded_at'))->not->toBeNull();
});

test('factory keeps distributed products as completed while each unit sees its own queue', function () {
    $guest = Guest::create(['name' => 'Product List Guest']);
    $order = Order::create([
        'order_id' => 'PRODUCT-LIST-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 300000,
        'status' => 'Proses Produksi',
    ]);
    $order->items()->createMany([
        [
            'product' => 'Factory Production Item',
            'qty' => 1,
            'price' => 100000,
            'source' => 'Order',
            'status' => 'Produksi',
            'factory_drawing_image' => 'orders/factory-drawings/factory-production-item.png',
        ],
        [
            'product' => 'Bali Distribution Item',
            'qty' => 1,
            'qty_completed' => 1,
            'price' => 100000,
            'source' => 'Order',
            'status' => 'Distribusi',
        ],
        [
            'product' => 'Unassigned Item',
            'qty' => 1,
            'price' => 100000,
            'source' => 'Order',
            'status' => 'None',
        ],
    ]);

    $this->actingAs(User::factory()->factoryAdmin()->create())
        ->get(route('order-products.index'))
        ->assertSee('Factory Production Item')
        ->assertSee('Bali Distribution Item')
        ->assertSee('Selesai di Factory')
        ->assertDontSee('Unassigned Item');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('order-products.index'))
        ->assertSee('Bali Distribution Item')
        ->assertDontSee('Factory Production Item')
        ->assertDontSee('Unassigned Item');

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('order-products.index'))
        ->assertSee('Factory Production Item')
        ->assertSee('Bali Distribution Item')
        ->assertDontSee('Unassigned Item');
});

test('comment replies cannot reference another order thread', function () {
    $admin = User::factory()->admin()->create();
    $guest = Guest::create(['name' => 'Thread Guest']);
    $firstOrder = Order::create([
        'order_id' => 'THREAD-ONE',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 0,
        'status' => 'None',
    ]);
    $secondOrder = Order::create([
        'order_id' => 'THREAD-TWO',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 0,
        'status' => 'None',
    ]);
    $comment = $firstOrder->comments()->create([
        'user_id' => $admin->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'First thread message.',
    ]);

    $this->actingAs($admin)
        ->from(route('orders.show', $secondOrder))
        ->post(route('orders.comments.store', $secondOrder), [
            'parent_id' => $comment->id,
            'body' => 'Cross-thread reply.',
        ])
        ->assertSessionHasErrors('parent_id');

    $this->assertDatabaseCount('order_comments', 1);
});

test('comment validation preserves entered text', function () {
    $owner = User::factory()->owner()->create();
    $guest = Guest::create(['name' => 'Validation Guest']);
    $order = Order::create([
        'order_id' => 'VALIDATION-ORDER',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 0,
        'status' => 'None',
    ]);

    $this->actingAs($owner)
        ->from(route('orders.show', $order))
        ->post(route('orders.comments.store', $order), [
            'business_unit' => 'invalid',
            'body' => 'Retained comment text.',
        ])
        ->assertSessionHasErrors('business_unit');

    $this->get(route('orders.show', $order))
        ->assertSee('Retained comment text.');
});
