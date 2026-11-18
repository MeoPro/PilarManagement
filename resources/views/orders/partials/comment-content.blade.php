<div class="order-comment-heading">
    <strong>
        {{ $comment->author?->name ?? 'Admin' }}
        · {{ $comment->business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}
    </strong>
    <small>{{ $comment->created_at->format('d/m/Y H:i') }} WITA</small>
</div>
@if($comment->user_id === auth()->id())
    <div class="order-comment-edit-actions">
        <button type="button" class="btn" data-comment-edit-toggle>Edit komentar</button>
        <form
            action="{{ route('orders.comments.update', [$comment->order_id, $comment->id]) }}"
            method="POST"
            class="order-comment-edit-form"
            data-order-comment-edit-form
            hidden
        >
            @csrf
            @method('PATCH')
            <label for="edit-order-comment-{{ $comment->id }}">Ubah komentar</label>
            <textarea
                id="edit-order-comment-{{ $comment->id }}"
                name="body"
                rows="3"
                maxlength="5000"
                required
            >{{ $comment->body }}</textarea>
            <p class="message" role="status" data-comment-feedback hidden></p>
            <p class="error" role="alert" data-comment-error hidden></p>
            <div class="order-comment-form-actions">
                <button type="submit" class="btn btn-primary">Simpan perubahan</button>
                <button type="button" class="btn" data-comment-edit-cancel>Batal</button>
            </div>
        </form>
    </div>
@endif
<p class="order-comment-body" data-comment-body>{{ $comment->body }}</p>
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
