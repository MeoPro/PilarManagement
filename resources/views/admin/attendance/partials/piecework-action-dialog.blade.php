<dialog class="piecework-action-dialog" data-piecework-action-dialog aria-labelledby="piecework-action-title">
    <form method="POST" class="piecework-action-form" data-piecework-action-form>
        @csrf
        <input type="hidden" name="_method" value="" data-piecework-method disabled>
        <h2 id="piecework-action-title" data-piecework-action-title></h2>
        <p data-piecework-action-product></p>
        <label>
            <span data-piecework-quantity-label>Jumlah barang</span>
            <input type="number" name="quantity" min="1" required data-piecework-action-quantity>
        </label>
        <button type="button" class="btn" data-piecework-fill-all>Semua</button>
        <label>
            <span>Alasan / catatan</span>
            <textarea name="note" rows="3" maxlength="1000" required data-piecework-action-note></textarea>
        </label>
        <div class="piecework-action-actions">
            <button type="button" class="btn" data-piecework-action-close>Batal</button>
            <button type="submit" class="btn primary" data-piecework-action-submit></button>
        </div>
    </form>
</dialog>
