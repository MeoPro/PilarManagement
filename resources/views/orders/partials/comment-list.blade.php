<div class="order-comment-list" data-order-comment-list>
    @forelse($order->comments as $comment)
        @include('orders.partials.comment-thread', ['comment' => $comment, 'order' => $order, 'returnTo' => $returnTo])
    @empty
        <p class="muted" data-order-comment-empty>Belum ada komentar pada order ini.</p>
    @endforelse
</div>
