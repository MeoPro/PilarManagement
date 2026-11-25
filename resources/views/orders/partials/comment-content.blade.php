<div class="order-comment-heading">
    <strong>
        {{ $comment->author?->name ?? 'Admin' }}
        · {{ $comment->business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}
    </strong>
    <small>{{ $comment->created_at->copy()->setTimezone(auth()->user()->timezone())->format('d/m/Y H:i') }} {{ auth()->user()->timezoneLabel() }}</small>
</div>
@if(!$comment->retracted_at)
    <div class="order-comment-edit-actions">
        @if($comment->user_id === auth()->id())
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
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
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
        @endif
        @if(auth()->user()->is_admin)
            <form
                action="{{ route('orders.comments.retract', [$comment->order_id, $comment->id]) }}"
                method="POST"
                class="order-comment-retract-form"
                onsubmit="return confirm('Tarik pesan ini dari diskusi? Isi pesan tetap disimpan untuk riwayat.')"
            >
                @csrf
                @method('PATCH')
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
                <button type="submit" class="btn">Tarik pesan</button>
            </form>
        @endif
    </div>
@endif
<p class="order-comment-body" data-comment-body>{{ $comment->retracted_at ? 'Pesan ditarik oleh '.($comment->retractedBy?->name ?? 'admin').'.' : $comment->body }}</p>
@if(!$comment->retracted_at && $comment->edited_at)
    <small class="order-comment-edited">Edited</small>
@endif
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
