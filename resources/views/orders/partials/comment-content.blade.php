<div class="order-comment-heading">
    <strong>
        {{ $comment->author?->name ?? 'Admin' }}
        · {{ $comment->business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}
    </strong>
    <small>{{ $comment->created_at->format('d/m/Y H:i') }} WITA</small>
</div>
<p class="order-comment-body">{{ $comment->body }}</p>
<div class="order-comment-status">
    @if($isRoot)
        <span data-comment-reply-status>{{ $comment->replies_count > 0 ? 'Sudah dibalas' : 'Belum dibalas' }}</span>
    @endif
    @if($comment->read_recipients_count > 0)
        <span class="is-read" aria-label="Sudah dibaca oleh penerima">✓✓ Dibaca</span>
    @else
        <span aria-label="Berhasil dikirim">✓ Terkirim</span>
    @endif
</div>
