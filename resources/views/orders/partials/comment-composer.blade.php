<form
    action="{{ route('orders.comments.store', $order) }}"
    method="POST"
    class="order-comment-new-form"
    data-order-comment-form
>
    @csrf
    <input type="hidden" name="return_to" value="{{ $returnTo }}">
    @if(count(auth()->user()->accessibleUnits()) > 1)
        <label for="comment-business-unit">Kirim sebagai</label>
        <select id="comment-business-unit" name="business_unit" required>
            @foreach(auth()->user()->accessibleUnits() as $unit)
                <option
                    value="{{ $unit }}"
                    @selected(empty(old('parent_id')) && old('business_unit', auth()->user()->activeBusinessUnit()) === $unit)
                >
                    {{ $unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali' }}
                </option>
            @endforeach
        </select>
    @endif
    <label for="new-order-comment">Tulis komentar</label>
    <textarea
        id="new-order-comment"
        name="body"
        rows="3"
        maxlength="5000"
        placeholder="Tulis komentar untuk unit lain..."
        required
    >{{ empty(old('parent_id')) ? old('body') : '' }}</textarea>
    <p class="message" role="status" data-comment-feedback hidden></p>
    <p class="error" role="alert" data-comment-error hidden></p>
    <div class="order-comment-form-actions">
        <button class="btn btn-primary" type="submit">Kirim Komentar</button>
    </div>
</form>
