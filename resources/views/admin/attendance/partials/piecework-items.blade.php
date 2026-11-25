<div class="piecework-item-list" data-piecework-list>
    @foreach($oldItems as $itemIndex => $item)
        @php($employeeAssignments = $employee->pieceworkAssignments->filter(fn ($assignment) => $assignment->order_item_id && $assignment->orderItem?->order))
        <div class="piecework-item-row" data-piecework-item>
            @if($employee->business_unit === \App\Models\User::UNIT_FACTORY)
                <label>
                    <span>SPK / Order</span>
                    <select name="piecework_items[{{ $itemIndex }}][order_id]" data-piecework-spk required>
                        <option value="">Pilih SPK</option>
                        @foreach($pieceworkOrders as $pieceworkOrder)
                            <option
                                value="{{ $pieceworkOrder->id }}"
                                @selected((string) ($item['order_id'] ?? '') === (string) $pieceworkOrder->id)
                            >
                                {{ $pieceworkOrder->order_id }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Produk dari SPK</span>
                    <select name="piecework_items[{{ $itemIndex }}][order_item_id]" data-piecework-order-product required>
                        <option value="">Pilih produk order</option>
                        @foreach($pieceworkOrders as $pieceworkOrder)
                            @foreach($pieceworkOrder->items as $orderProduct)
                                <option
                                    value="{{ $orderProduct->id }}"
                                    data-order-id="{{ $pieceworkOrder->id }}"
                                    data-product="{{ $orderProduct->product }}"
                                    @selected((string) ($item['order_item_id'] ?? '') === (string) $orderProduct->id && empty($item['assignment_id']))
                                >
                                    {{ $orderProduct->product }} · tanpa penugasan (sisa {{ $orderProduct->factory_status === 'Service' ? $orderProduct->factory_production_qty_remaining : $orderProduct->factory_qty_remaining }})
                                </option>
                            @endforeach
                        @endforeach
                        @foreach($employeeAssignments as $assignment)
                            <option
                                value="{{ $assignment->order_item_id }}"
                                data-assignment-id="{{ $assignment->id }}"
                                data-order-id="{{ $assignment->orderItem->order_id }}"
                                data-product="{{ $assignment->product_name }}"
                                data-unit-price="{{ $assignment->unit_price ?? '' }}"
                                data-remaining="{{ max($assignment->target_quantity - (int) $assignment->completed_quantity - (int) $assignment->cancelled_quantity - (int) $assignment->produced_quantity, 0) }}"
                                data-deadline="{{ $assignment->target_deadline->toDateString() }}"
                                @disabled($assignment->target_quantity <= (int) $assignment->completed_quantity + (int) $assignment->cancelled_quantity + (int) $assignment->produced_quantity)
                                @selected((string) ($item['order_item_id'] ?? '') === (string) $assignment->order_item_id && (string) ($item['assignment_id'] ?? '') === (string) $assignment->id)
                            >
                                {{ $assignment->product_name }} · sisa target {{ max($assignment->target_quantity - (int) $assignment->completed_quantity - (int) $assignment->cancelled_quantity - (int) $assignment->produced_quantity, 0) }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
            @if($employee->business_unit === \App\Models\User::UNIT_BALI)
                <input type="hidden" name="piecework_items[{{ $itemIndex }}][assignment_id]" value="{{ $item['assignment_id'] ?? '' }}" data-piecework-assignment-id>
            @else
                <input type="hidden" name="piecework_items[{{ $itemIndex }}][assignment_id]" value="{{ $item['assignment_id'] ?? '' }}" data-piecework-assignment-id>
            @endif
            <label>
                <span>Nama barang</span>
                <input type="text" name="piecework_items[{{ $itemIndex }}][product_name]" value="{{ $item['product_name'] ?? '' }}" maxlength="255" data-piecework-product-name required>
            </label>
            <label>
                <span>Jumlah</span>
                <input class="piecework-quantity-input" type="number" name="piecework_items[{{ $itemIndex }}][quantity]" value="{{ filled($item['quantity'] ?? null) ? $item['quantity'] : 1 }}" min="1" step="1" data-piecework-quantity required>
            </label>
            <label>
                <span>Harga per barang</span>
                <input type="text" name="piecework_items[{{ $itemIndex }}][unit_price]" value="{{ $item['unit_price'] ?? '' }}" inputmode="decimal" data-piecework-price autocomplete="off" required>
            </label>
            <div class="piecework-deadline-control" data-deadline-control data-deadline-today="{{ $localNow->toDateString() }}">
                <span class="piecework-deadline-heading">Deadline</span>
                <label>
                    <span>Tanggal</span>
                    <input type="date" name="piecework_items[{{ $itemIndex }}][target_deadline]" value="{{ $item['target_deadline'] ?? $localNow->toDateString() }}" min="{{ $localNow->toDateString() }}" data-deadline-date required>
                </label>
                <label>
                    <span>Hari dari hari ini</span>
                    <input class="piecework-deadline-days-input" type="number" min="0" step="1" inputmode="numeric" data-deadline-days>
                </label>
            </div>
            <div class="piecework-item-total">
                <span>Total barang</span>
                <strong data-piecework-line-total>Rp 0</strong>
            </div>
            <button type="button" class="btn piecework-item-remove" data-remove-piecework-item aria-label="Hapus barang">Hapus</button>
        </div>
    @endforeach
</div>
<div class="piecework-form-footer">
    <button type="button" class="btn" data-add-piecework-item>+ Tambah produk</button>
    <span>Total borongan: <strong data-piecework-total>Rp 0</strong></span>
</div>
