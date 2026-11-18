<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderPaymentController extends Controller
{
    public function store(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSalesWorkflow() && $user->canAccessMenu('orders'), 403);

        $this->normalizeAmount($request);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_at' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.today($user->timezone())->toDateString(),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $amount = round((float) $validated['amount'], 2);

        DB::transaction(function () use ($order, $validated, $amount, $user): void {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($lockedOrder->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'amount' => 'Order yang dibatalkan tidak dapat menerima pembayaran.',
                ]);
            }

            $remainingAmount = max(
                round((float) $lockedOrder->total_order - (float) $lockedOrder->paid_amount, 2),
                0,
            );
            if ($amount > $remainingAmount) {
                throw ValidationException::withMessages([
                    'amount' => 'Jumlah pembayaran melebihi sisa tagihan Rp '
                        .number_format($remainingAmount, 0, ',', '.').'.',
                ]);
            }

            $lockedOrder->payments()->create([
                'amount' => $amount,
                'paid_at' => $validated['paid_at'],
                'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
                'received_by_id' => $user->id,
            ]);
            $lockedOrder->update([
                'paid_amount' => round((float) $lockedOrder->paid_amount + $amount, 2),
                'last_edit' => now(),
            ]);
            $lockedOrder->events()->create([
                'type' => 'payment_received',
                'title' => 'Pembayaran order diterima',
                'description' => "{$lockedOrder->order_id}: Rp "
                    .number_format($amount, 0, ',', '.')
                    ." diterima pada {$validated['paid_at']}.",
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Pembayaran berhasil dicatat.');
    }

    private function normalizeAmount(Request $request): void
    {
        $amount = $request->input('amount');
        if (! is_string($amount)) {
            return;
        }

        $amount = trim($amount);
        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $amount) === 1) {
            $request->merge(['amount' => str_replace(',', '', $amount)]);
        }
    }
}
