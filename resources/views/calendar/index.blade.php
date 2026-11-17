@extends('layouts.admin')

@section('title', 'Kalender Order')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Jadwal operasional</p>
            <h1>Kalender Order</h1>
            <p>Order masuk, target pengiriman, dan riwayat perubahan order.</p>
        </div>
        @if(auth()->user()->canAccessMenu('orders'))
            <a class="btn btn-primary" href="{{ route('orders.create') }}">Buat order</a>
        @endif
    </div>

    <div class="calendar-layout">
        <section class="calendar-panel" aria-label="Kalender bulan {{ $monthLabel }}">
            <header class="calendar-toolbar">
                <div class="calendar-month">
                    <span class="calendar-month-icon"><x-layout.icon name="calendar" /></span>
                    <h2>{{ $monthLabel }}</h2>
                </div>
                <div class="calendar-navigation">
                    <a class="btn btn-secondary" href="{{ route('calendar.index') }}">Hari ini</a>
                    <a class="btn btn-secondary calendar-arrow" href="{{ route('calendar.index', ['month' => $previousMonth]) }}" aria-label="Bulan sebelumnya" title="Bulan sebelumnya">&lsaquo;</a>
                    <a class="btn btn-secondary calendar-arrow" href="{{ route('calendar.index', ['month' => $nextMonth]) }}" aria-label="Bulan berikutnya" title="Bulan berikutnya">&rsaquo;</a>
                </div>
            </header>

            <div class="calendar-grid" role="grid" aria-label="{{ $monthLabel }}">
                @foreach($weekdays as $weekday)
                    <div class="calendar-weekday" role="columnheader">{{ $weekday }}</div>
                @endforeach

                @foreach($calendarDays as $calendarDay)
                    @php
                        $dayKey = $calendarDay->toDateString();
                        $dayEvents = $eventsByDate->get($dayKey, collect());
                    @endphp
                    <div
                        class="calendar-day {{ $calendarDay->format('Y-m') !== $monthParameter ? 'is-outside' : '' }} {{ $dayKey === $today ? 'is-today' : '' }}"
                        role="gridcell"
                        aria-label="{{ $calendarDay->format('d/m/Y') }}, {{ $dayEvents->count() }} aktivitas"
                    >
                        <span class="calendar-date">{{ $calendarDay->day }}</span>
                        <div class="calendar-day-events">
                            @foreach($dayEvents->take(3) as $event)
                                @if($event['url'])
                                    <a class="calendar-event is-{{ $event['style'] }}" href="{{ $event['url'] }}" title="{{ $event['description'] }}" aria-label="{{ $event['description'] }}">
                                @else
                                    <span class="calendar-event is-{{ $event['style'] }}" title="{{ $event['description'] }}" aria-label="{{ $event['description'] }}">
                                @endif
                                    <span class="calendar-event-dot"></span>
                                    <span class="calendar-event-label">{{ $event['label'] }}: {{ $event['summary'] }}</span>
                                    @if($event['time'])
                                        <time class="calendar-event-time" datetime="{{ $event['dateTime'] }}">{{ $event['time'] }}</time>
                                    @endif
                                @if($event['url'])
                                    </a>
                                @else
                                    </span>
                                @endif
                            @endforeach
                            @if($dayEvents->count() > 3)
                                <span class="calendar-more">+{{ $dayEvents->count() - 3 }} lainnya</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <aside class="calendar-sidebar">
            <section class="calendar-panel calendar-summary">
                <h2>Ringkasan {{ $monthLabel }}</h2>
                <div class="calendar-summary-grid">
                    <div class="calendar-summary-card is-order-created">
                        <span>Order masuk</span>
                        <strong>{{ $summary['incoming'] }}</strong>
                    </div>
                    <div class="calendar-summary-card is-delivery-target">
                        <span>Target kirim</span>
                        <strong>{{ $summary['delivery'] }}</strong>
                    </div>
                    <div class="calendar-summary-card is-status-order">
                        <span>Perubahan status</span>
                        <strong>{{ $summary['status'] }}</strong>
                    </div>
                    <div class="calendar-summary-card is-item-added">
                        <span>Perubahan lain</span>
                        <strong>{{ $summary['other'] }}</strong>
                    </div>
                </div>
            </section>

            <section class="calendar-panel calendar-upcoming">
                <div class="calendar-section-heading">
                    <h2>Agenda mendatang</h2>
                    <span>{{ $upcomingEvents->count() }}</span>
                </div>
                @forelse($upcomingEvents as $event)
                    @if($event['url'])
                        <a class="calendar-upcoming-item" href="{{ $event['url'] }}">
                    @else
                        <div class="calendar-upcoming-item">
                    @endif
                        <time class="calendar-upcoming-date" datetime="{{ $event['date'] }}">
                            <strong>{{ \Illuminate\Support\Carbon::parse($event['date'])->format('d') }}</strong>
                            <span>{{ \Illuminate\Support\Carbon::parse($event['date'])->format('M') }}</span>
                        </time>
                        <span class="calendar-upcoming-copy">
                            <strong>{{ $event['label'] }}</strong>
                            <small>{{ $event['summary'] }}@if($event['time']) · {{ $event['time'] }}@endif</small>
                        </span>
                        <span class="calendar-event-dot is-{{ $event['style'] }}"></span>
                    @if($event['url'])
                        </a>
                    @else
                        </div>
                    @endif
                @empty
                    <p class="calendar-empty">Belum ada agenda mendatang bulan ini.</p>
                @endforelse
            </section>

            <section class="calendar-panel calendar-legend">
                <h2>Keterangan</h2>
                @foreach($legend as $item)
                    <div class="calendar-legend-item">
                        <span class="calendar-event-dot is-{{ $item['style'] }}"></span>
                        <span>{{ $item['label'] }}</span>
                    </div>
                @endforeach
            </section>
        </aside>
    </div>
@endsection
