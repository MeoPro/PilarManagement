<article class="order-comment" data-order-comment-id="{{ $comment->id }}">
    @include('orders.partials.comment-content', ['comment' => $comment, 'isRoot' => false, 'returnTo' => $returnTo])
</article>
