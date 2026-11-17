<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $timezone = $request->user()->timezone();
        $localNow = now($timezone);
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $monthParameter = $validated['month'] ?? $localNow->format('Y-m');
        $monthStart = Carbon::parse($monthParameter, $timezone)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $calendarStart = $monthStart->copy()->startOfWeek(Carbon::SUNDAY);
        $calendarEnd = $monthEnd->copy()->endOfWeek(Carbon::SATURDAY);
        $rangeStart = $calendarStart->copy()->startOfDay();
        $rangeEnd = $calendarEnd->copy()->endOfDay();
        $storageRangeStart = $rangeStart->copy()->setTimezone(config('app.timezone'));
        $storageRangeEnd = $rangeEnd->copy()->setTimezone(config('app.timezone'));

        $calendarDays = [];
        $calendarDay = $rangeStart->copy();

        while ($calendarDay->lte($rangeEnd)) {
            $calendarDays[] = $calendarDay->copy();
            $calendarDay->addDay();
        }

        $calendarEvents = collect();
        $canOpenOrders = $request->user()->canAccessMenu('orders');

        $incomingOrders = Order::query()
            ->whereBetween('created_at', [$storageRangeStart, $storageRangeEnd])
            ->oldest('created_at')
            ->get(['id', 'order_id', 'created_at']);

        foreach ($incomingOrders as $order) {
            $createdAt = $order->created_at->copy()->setTimezone($timezone);
            $calendarEvents->push([
                'date' => $createdAt->toDateString(),
                'dateTime' => $createdAt->format('Y-m-d H:i:s'),
                'time' => $createdAt->format('H:i'),
                'type' => 'order_created',
                'style' => 'order-created',
                'label' => 'Order masuk',
                'summary' => $order->order_id,
                'description' => "Order masuk: {$order->order_id}",
                'url' => $canOpenOrders ? route('orders.show', $order) : null,
            ]);
        }

        $deliveryOrders = Order::query()
            ->whereBetween('deadline', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->where('status', '!=', 'Cancel')
            ->orderBy('deadline')
            ->get(['id', 'order_id', 'deadline']);

        foreach ($deliveryOrders as $order) {
            $deliveryDate = $order->deadline->toDateString();
            $calendarEvents->push([
                'date' => $deliveryDate,
                'dateTime' => $deliveryDate.' 00:00:00',
                'time' => null,
                'type' => 'delivery_target',
                'style' => 'delivery-target',
                'label' => 'Target kirim',
                'summary' => $order->order_id,
                'description' => "Target pengiriman: {$order->order_id}",
                'url' => $canOpenOrders ? route('orders.show', $order) : null,
            ]);
        }

        $statusEvents = OrderEvent::query()
            ->with('order:id,order_id')
            ->whereBetween('occurred_at', [$storageRangeStart, $storageRangeEnd])
            ->oldest('occurred_at')
            ->get();

        foreach ($statusEvents as $event) {
            $occurredAt = $event->occurred_at->copy()->setTimezone($timezone);
            $style = match ($event->type) {
                'order_status_changed' => 'status-order',
                'item_status_changed' => 'status-item',
                'deadline_changed' => 'deadline-changed',
                'item_added' => 'item-added',
                default => 'status-item',
            };

            $calendarEvents->push([
                'date' => $occurredAt->toDateString(),
                'dateTime' => $occurredAt->format('Y-m-d H:i:s'),
                'time' => $occurredAt->format('H:i'),
                'type' => $event->type,
                'style' => $style,
                'label' => $event->title,
                'summary' => $event->description,
                'description' => $event->description,
                'url' => $canOpenOrders ? route('orders.show', $event->order) : null,
            ]);
        }

        $calendarEvents = $calendarEvents
            ->sortBy('dateTime')
            ->values();

        $monthlyEvents = $calendarEvents->filter(
            fn (array $event): bool => $event['date'] >= $monthStart->toDateString()
                && $event['date'] <= $monthEnd->toDateString(),
        );

        $eventsByDate = $calendarEvents->groupBy('date');
        $today = $localNow->toDateString();
        $upcomingEvents = $monthlyEvents
            ->filter(fn (array $event): bool => $event['date'] >= $today)
            ->sortBy('dateTime')
            ->take(8)
            ->values();

        $summary = [
            'incoming' => $monthlyEvents->where('type', 'order_created')->count(),
            'delivery' => $monthlyEvents->where('type', 'delivery_target')->count(),
            'status' => $monthlyEvents->whereIn('type', [
                'order_status_changed',
                'item_status_changed',
            ])->count(),
            'other' => $monthlyEvents->whereIn('type', [
                'deadline_changed',
                'item_added',
            ])->count(),
        ];

        $monthNames = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];
        $monthLabel = $monthNames[$monthStart->month - 1].' '.$monthStart->year;
        $weekdays = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
        $legend = [
            ['style' => 'order-created', 'label' => 'Order masuk'],
            ['style' => 'delivery-target', 'label' => 'Target kirim'],
            ['style' => 'status-order', 'label' => 'Status order'],
            ['style' => 'status-item', 'label' => 'Status produk'],
            ['style' => 'deadline-changed', 'label' => 'Perubahan target'],
            ['style' => 'item-added', 'label' => 'Produk ditambahkan'],
        ];

        return view('calendar.index', [
            'calendarDays' => $calendarDays,
            'eventsByDate' => $eventsByDate,
            'legend' => $legend,
            'monthLabel' => $monthLabel,
            'monthParameter' => $monthStart->format('Y-m'),
            'nextMonth' => $monthStart->copy()->addMonth()->format('Y-m'),
            'previousMonth' => $monthStart->copy()->subMonth()->format('Y-m'),
            'summary' => $summary,
            'today' => $today,
            'timezone' => $timezone,
            'upcomingEvents' => $upcomingEvents,
            'weekdays' => $weekdays,
        ]);
    }
}
