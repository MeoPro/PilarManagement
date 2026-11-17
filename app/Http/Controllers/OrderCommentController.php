<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderCommentRecipient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderCommentController extends Controller
{
    public function store(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $accessibleUnits = $user->accessibleUnits();
        abort_if($accessibleUnits === [], 403);

        $rules = [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ];

        if (count($accessibleUnits) > 1) {
            $rules['business_unit'] = ['required', Rule::in($accessibleUnits)];
        }

        $validated = $request->validate($rules);
        $businessUnit = count($accessibleUnits) === 1
            ? $accessibleUnits[0]
            : $validated['business_unit'];
        $parent = null;

        if (! empty($validated['parent_id'])) {
            $parent = $order->comments()->find($validated['parent_id']);

            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Balasan harus berada dalam diskusi order ini.',
                ]);
            }
        }

        $comment = DB::transaction(function () use ($user, $order, $validated, $businessUnit, $parent): OrderComment {
            $comment = $order->comments()->create([
                'user_id' => $user->id,
                'parent_id' => $parent?->id,
                'business_unit' => $businessUnit,
                'body' => trim($validated['body']),
            ]);
            $recipientUnit = $businessUnit === User::UNIT_BALI
                ? User::UNIT_FACTORY
                : User::UNIT_BALI;

            $recipients = User::query()
                ->where('is_admin', true)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $recipient): bool => $recipient->id !== $user->id
                    && $recipient->canAccessMenu('orders')
                    && ($recipient->isOwner() || $recipient->canAccessUnit($recipientUnit)));

            foreach ($recipients as $recipient) {
                $comment->recipients()->create([
                    'user_id' => $recipient->id,
                ]);
            }

            if ($parent) {
                $parent->recipients()
                    ->where('user_id', $user->id)
                    ->whereNull('read_at')
                    ->update([
                        'read_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            return $comment;
        });

        if ($request->expectsJson()) {
            $comment->load(['author', 'replies.author']);
            $comment->loadCount(['readRecipients', 'replies']);

            $html = $comment->parent_id === null
                ? view('orders.partials.comment-thread', compact('comment', 'order'))->render()
                : view('orders.partials.comment-entry', compact('comment'))->render();

            return response()->json([
                'comment' => [
                    'id' => $comment->id,
                    'parent_id' => $comment->parent_id,
                ],
                'html' => $html,
                'message' => 'Komentar berhasil dikirim.',
            ]);
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Komentar berhasil dikirim.');
    }

    public function reminders(Request $request): JsonResponse
    {
        $recipients = OrderCommentRecipient::query()
            ->with([
                'comment' => fn ($query) => $query
                    ->withCount('replies')
                    ->with(['author', 'order:id,order_id']),
            ])
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->whereNull('reminded_at')
            ->whereHas('comment', fn ($query) => $query->where('created_at', '<=', now()->subDays(2)))
            ->oldest()
            ->limit(10)
            ->get();

        if ($recipients->isEmpty()) {
            return response()->json(['reminders' => []]);
        }

        OrderCommentRecipient::query()
            ->whereKey($recipients->modelKeys())
            ->whereNull('reminded_at')
            ->update([
                'reminded_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'reminders' => $recipients->map(fn (OrderCommentRecipient $recipient): array => [
                'order_id' => $recipient->comment->order->order_id,
                'url' => route('orders.show', $recipient->comment->order),
                'sender' => $recipient->comment->author?->name ?? 'Admin',
                'business_unit' => $recipient->comment->business_unit,
                'body' => Str::limit($recipient->comment->body, 140),
                'has_replies' => $recipient->comment->replies_count > 0,
                'created_at' => $recipient->comment->created_at->format('d/m/Y H:i'),
            ])->values(),
        ]);
    }
}
