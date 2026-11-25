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
        $returnTargets = $user->isSalesWorkflow() ? ['show', 'edit'] : ['show'];

        $rules = [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
            'return_to' => ['nullable', Rule::in($returnTargets)],
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

            if (! $parent || $parent->retracted_at !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Balasan harus berada pada pesan aktif dalam diskusi order ini.',
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
            $comment->load(['author', 'retractedBy', 'replies.author', 'replies.retractedBy']);
            $comment->loadCount(['readRecipients', 'replies']);

            $html = $this->renderCommentHtml($comment, $order, $validated['return_to'] ?? 'show');

            return response()->json([
                'comment' => [
                    'id' => $comment->id,
                    'parent_id' => $comment->parent_id,
                ],
                'html' => $html,
                'message' => 'Komentar berhasil dikirim.',
            ]);
        }

        return $this->redirectToOrder($order, $validated['return_to'] ?? 'show')
            ->with('success', 'Komentar berhasil dikirim.');
    }

    public function update(Request $request, Order $order, OrderComment $comment): JsonResponse|RedirectResponse
    {
        abort_unless($comment->user_id === $request->user()->id && $comment->retracted_at === null, 403);
        $returnTargets = $request->user()->isSalesWorkflow() ? ['show', 'edit'] : ['show'];

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'return_to' => ['nullable', Rule::in($returnTargets)],
        ]);

        $body = trim($validated['body']);

        DB::transaction(function () use ($comment, $body): void {
            $comment->update([
                'body' => $body,
                'edited_at' => now(),
            ]);
        });

        $comment->refresh()->load(['author', 'retractedBy', 'replies.author', 'replies.retractedBy']);
        $comment->loadCount(['readRecipients', 'replies']);

        if ($request->expectsJson()) {
            $html = $this->renderCommentHtml($comment, $order, $validated['return_to'] ?? 'show');

            return response()->json([
                'comment' => [
                    'id' => $comment->id,
                    'parent_id' => $comment->parent_id,
                ],
                'html' => $html,
                'message' => 'Komentar berhasil diperbarui.',
            ]);
        }

        return $this->redirectToOrder($order, $validated['return_to'] ?? 'show')
            ->with('success', 'Komentar berhasil diperbarui.');
    }

    public function retract(Request $request, Order $order, OrderComment $comment): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $returnTargets = $request->user()->isSalesWorkflow() ? ['show', 'edit'] : ['show'];

        $validated = $request->validate([
            'return_to' => ['nullable', Rule::in($returnTargets)],
        ]);
        $returnTo = $validated['return_to'] ?? 'show';

        DB::transaction(function () use ($order, $comment, $request): void {
            $lockedComment = $order->comments()
                ->whereKey($comment->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedComment->retracted_at !== null) {
                return;
            }

            $lockedComment->update([
                'retracted_at' => now(),
                'retracted_by_id' => $request->user()->id,
            ]);
            $lockedComment->recipients()
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $comment->refresh()->load(['author', 'retractedBy', 'replies.author', 'replies.retractedBy']);
        $comment->loadCount(['readRecipients', 'replies']);

        if ($request->expectsJson()) {
            return response()->json([
                'comment' => [
                    'id' => $comment->id,
                    'parent_id' => $comment->parent_id,
                ],
                'html' => $this->renderCommentHtml($comment, $order, $returnTo),
                'message' => 'Pesan berhasil ditarik.',
            ]);
        }

        return $this->redirectToOrder($order, $returnTo)
            ->with('success', 'Pesan berhasil ditarik dari diskusi.');
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
            ->whereHas('comment', fn ($query) => $query
                ->whereNull('retracted_at')
                ->where('created_at', '<=', now()->subDays(2)))
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

        $timezone = $request->user()->timezone();
        $timezoneLabel = $request->user()->timezoneLabel();

        return response()->json([
            'reminders' => $recipients->map(fn (OrderCommentRecipient $recipient): array => [
                'order_id' => $recipient->comment->order->order_id,
                'url' => route('orders.show', $recipient->comment->order),
                'sender' => $recipient->comment->author?->name ?? 'Admin',
                'business_unit' => $recipient->comment->business_unit,
                'body' => Str::limit($recipient->comment->body, 140),
                'has_replies' => $recipient->comment->replies_count > 0,
                'created_at' => $recipient->comment->created_at->copy()->setTimezone($timezone)->format('d/m/Y H:i').' '.$timezoneLabel,
            ])->values(),
        ]);
    }

    private function renderCommentHtml(OrderComment $comment, Order $order, string $returnTo): string
    {
        return $comment->parent_id === null
            ? view('orders.partials.comment-thread', compact('comment', 'order', 'returnTo'))->render()
            : view('orders.partials.comment-entry', compact('comment', 'returnTo'))->render();
    }

    private function redirectToOrder(Order $order, string $returnTo): RedirectResponse
    {
        return redirect()->route($returnTo === 'edit' ? 'orders.edit' : 'orders.show', $order);
    }
}
