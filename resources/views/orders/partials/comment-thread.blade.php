<article
    class="order-comment"
    data-order-comment-thread="{{ $comment->id }}"
    data-order-comment-id="{{ $comment->id }}"
>
    @include('orders.partials.comment-content', ['comment' => $comment, 'isRoot' => true, 'returnTo' => $returnTo])

    @if($comment->replies->isNotEmpty())
        <div class="order-comment-replies" data-order-comment-replies>
            @foreach($comment->replies as $reply)
                @include('orders.partials.comment-entry', ['comment' => $reply, 'returnTo' => $returnTo])
            @endforeach
        </div>
    @endif

    @unless($comment->retracted_at)
        <form
            action="{{ route('orders.comments.store', $order) }}"
            method="POST"
            class="order-comment-reply-form"
            data-order-comment-form
        >
            @csrf
            <input type="hidden" name="parent_id" value="{{ $comment->id }}">
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
            @if(count(auth()->user()->accessibleUnits()) > 1)
                <select name="business_unit" aria-label="Balas sebagai unit" required>
                    @foreach(auth()->user()->accessibleUnits() as $unit)
                        <option
                            value="{{ $unit }}"
                            @selected(old('parent_id') == $comment->id && old('business_unit', auth()->user()->activeBusinessUnit()) === $unit)
                        >
                            {{ $unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}
                        </option>
                    @endforeach
                </select>
            @endif
            <textarea name="body" rows="2" maxlength="5000" placeholder="Tulis balasan..." required>{{ old('parent_id') == $comment->id ? old('body') : '' }}</textarea>
            <p class="message" role="status" data-comment-feedback hidden></p>
            <p class="error" role="alert" data-comment-error hidden></p>
            <div class="order-comment-form-actions">
                <button class="btn" type="submit">Balas</button>
            </div>
        </form>
    @endunless
</article>
