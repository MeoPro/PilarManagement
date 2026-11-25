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
        ->get(route('orders.edit', $order))
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
        ->get(route('orders.edit', $order))
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

test('order and comment timestamps use the viewing business unit timezone', function () {
    $this->travelTo('2026-03-10 10:00:00');
    $baliAdmin = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $guest = Guest::create(['name' => 'Timezone Guest']);
    $order = Order::create([
        'order_id' => 'ORDER-TIMEZONE-DISPLAY',
        'guest_id' => $guest->id,
        'guest_name' => $guest->name,
        'total_order' => 100000,
        'status' => 'Proses Produksi',
    ]);
    $order->items()->create([
        'product' => 'Timezone Display Product',
        'qty' => 1,
        'price' => 100000,
        'source' => 'Order',
        'status' => 'Produksi',
        'factory_status' => 'Produksi',
        'factory_drawing_image' => 'orders/factory-drawings/timezone-display.png',
    ]);
    $order->comments()->create([
        'user_id' => $baliAdmin->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'Timezone display comment.',
    ]);

    $this->actingAs($factoryAdmin)
        ->get(route('orders.show', $order))
        ->assertSeeText('Terakhir diubah: 10 Mar 2026 09:00 WIB')
        ->assertSeeText('10/03/2026 09:00 WIB');

    $this->actingAs($baliAdmin)
        ->get(route('orders.edit', $order))
        ->assertSeeText('10/03/2026 10:00 WITA');
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
        ->assertJsonPath('reminders.0.body', 'Waiting for an update.')
        ->assertJsonPath('reminders.0.created_at', '10/03/2026 08:00 WIB');

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

    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->put(route('admin.business-unit-preferences.update'), [
            'business_unit' => User::UNIT_FACTORY,
        ]);

    $this->actingAs($owner)
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
        ->from(route('orders.edit', $order))
        ->post(route('orders.comments.store', $order), [
            'business_unit' => 'invalid',
            'body' => 'Retained comment text.',
        ])
        ->assertSessionHasErrors('business_unit');

    $this->get(route('orders.edit', $order))
        ->assertSee('Retained comment text.');
});

test('comment authors can edit their comments from the edit page', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::create([
        'order_id' => 'EDITABLE-COMMENT-ORDER',
        'guest_name' => 'Editable Comment Guest',
        'total_order' => 100000,
        'status' => 'None',
    ]);
    $comment = $order->comments()->create([
        'user_id' => $admin->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'Original comment.',
    ]);

    $this->actingAs($admin)
        ->get(route('orders.edit', $order))
        ->assertSee('Edit komentar')
        ->assertSee('Original comment.');

    $updatedBody = '<script>alert(1)</script>Updated comment.';
    $response = $this->patchJson(route('orders.comments.update', [$order, $comment]), [
        'body' => $updatedBody,
    ])
        ->assertOk()
        ->assertJsonPath('comment.id', $comment->id)
        ->assertJsonPath('message', 'Komentar berhasil diperbarui.');

    expect($response->json('html'))
        ->toContain('&lt;script&gt;')
        ->toContain('Edited')
        ->not->toContain($updatedBody);
    $this->assertDatabaseHas('order_comments', [
        'id' => $comment->id,
        'body' => $updatedBody,
    ]);
    expect($comment->fresh()->edited_at)->not->toBeNull();

    $this->get(route('orders.edit', $order))
        ->assertSee('Edited');
});

test('only the comment author can edit it and comments cannot be edited across orders', function () {
    $author = User::factory()->admin()->create();
    $otherAdmin = User::factory()->factoryAdmin()->create();
    $order = Order::create([
        'order_id' => 'COMMENT-AUTHOR-ORDER',
        'guest_name' => 'Comment Author Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);
    $otherOrder = Order::create([
        'order_id' => 'COMMENT-OTHER-ORDER',
        'guest_name' => 'Other Comment Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);
    $comment = $order->comments()->create([
        'user_id' => $author->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'Author-only comment.',
    ]);

    $this->actingAs($otherAdmin)
        ->get(route('orders.show', $order))
        ->assertDontSee('Edit komentar');

    $this->patchJson(route('orders.comments.update', [$order, $comment]), [
        'body' => 'Unauthorized update.',
    ])->assertForbidden();

    $this->actingAs($author)
        ->patchJson(route('orders.comments.update', [$otherOrder, $comment]), [
            'body' => 'Cross-order update.',
        ])
        ->assertNotFound();

    $this->assertDatabaseHas('order_comments', [
        'id' => $comment->id,
        'body' => 'Author-only comment.',
    ]);
});

test('comment edit rejects an empty body without changing the saved comment', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::create([
        'order_id' => 'COMMENT-VALIDATION-ORDER',
        'guest_name' => 'Comment Validation Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);
    $comment = $order->comments()->create([
        'user_id' => $admin->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'Keep this comment.',
    ]);

    $this->actingAs($admin)
        ->patchJson(route('orders.comments.update', [$order, $comment]), [
            'body' => '',
        ])
        ->assertInvalid(['body']);

    $this->assertDatabaseHas('order_comments', [
        'id' => $comment->id,
        'body' => 'Keep this comment.',
    ]);
});

test('order edit page includes a discussion composer and returns sent comments to edit', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::create([
        'order_id' => 'EDIT-DISCUSSION-ORDER',
        'guest_name' => 'Discussion Edit Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);

    $this->actingAs($admin)
        ->get(route('orders.edit', $order))
        ->assertSee('id="order-discussion"', false)
        ->assertSee('name="body"', false)
        ->assertSee('name="return_to" value="edit"', false);

    $this->post(route('orders.comments.store', $order), [
        'body' => 'Comment sent from edit.',
        'return_to' => 'edit',
    ])->assertRedirect(route('orders.edit', $order));

    $this->assertDatabaseHas('order_comments', [
        'order_id' => $order->id,
        'body' => 'Comment sent from edit.',
    ]);
});

test('admins can retract comments while preserving the original text and replies for audit', function () {
    $author = User::factory()->admin()->create();
    $factoryAdmin = User::factory()->factoryAdmin()->create();
    $order = Order::create([
        'order_id' => 'RETRACT-COMMENT-ORDER',
        'guest_name' => 'Retracted Comment Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);
    $comment = $order->comments()->create([
        'user_id' => $author->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'Private message that should be withdrawn.',
    ]);
    $reply = $order->comments()->create([
        'user_id' => $factoryAdmin->id,
        'parent_id' => $comment->id,
        'business_unit' => User::UNIT_FACTORY,
        'body' => 'Reply remains in the audit thread.',
    ]);
    $recipient = $comment->recipients()->create(['user_id' => $factoryAdmin->id]);

    $this->actingAs($factoryAdmin)
        ->patchJson(route('orders.comments.retract', [$order, $comment]), [
            'return_to' => 'show',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Pesan berhasil ditarik.')
        ->assertJsonPath('comment.id', $comment->id);

    $discussionPage = $this->actingAs($author)
        ->get(route('orders.edit', $order));

    expect($discussionPage->getContent())
        ->toContain("Pesan ditarik oleh {$factoryAdmin->name}.")
        ->toContain('Reply remains in the audit thread.')
        ->not->toContain('Private message that should be withdrawn.');
    $this->assertDatabaseHas('order_comments', [
        'id' => $comment->id,
        'body' => 'Private message that should be withdrawn.',
        'retracted_by_id' => $factoryAdmin->id,
    ]);

    expect($recipient->fresh()->read_at)->not->toBeNull();
    $this->assertDatabaseHas('order_comments', [
        'id' => $reply->id,
        'body' => 'Reply remains in the audit thread.',
    ]);
});

test('non-admin users cannot retract order comments', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['is_admin' => false]);
    $order = Order::create([
        'order_id' => 'RETRACT-COMMENT-FORBIDDEN',
        'guest_name' => 'Retract Authorization Guest',
        'total_order' => 0,
        'status' => 'None',
    ]);
    $comment = $order->comments()->create([
        'user_id' => $admin->id,
        'business_unit' => User::UNIT_BALI,
        'body' => 'Keep this comment visible.',
    ]);

    $this->actingAs($user)
        ->patchJson(route('orders.comments.retract', [$order, $comment]), [
            'return_to' => 'show',
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('order_comments', [
        'id' => $comment->id,
        'body' => 'Keep this comment visible.',
        'retracted_at' => null,
    ]);
});
