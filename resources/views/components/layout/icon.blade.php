@props(['name'])

<svg {{ $attributes->merge(['class' => 'nav-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('dashboard')
            <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
            <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
            <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
            <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
            @break
        @case('eye')
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
            @break
        @case('pencil')
            <path d="m15 5 4 4M4 20l4-.8L19.2 8a2.1 2.1 0 0 0-3-3L5 16.2 4 20Z" />
            @break
        @case('calendar')
            <rect x="3.5" y="5" width="17" height="16" rx="2" />
            <path d="M8 3v4M16 3v4M3.5 10h17" />
            @break
        @case('trash')
            <path d="M4 7h16M10 11v6M14 11v6M5 7l1 14h12l1-14M9 7V4h6v3" />
            @break
        @case('orders')
            <path d="M7 3.5h7l4 4v13H7a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2Z" />
            <path d="M14 3.5v4h4M9 12h6M9 16h6" />
            @break
        @case('guests')
            <circle cx="9" cy="8" r="3.5" />
            <path d="M3 20v-1.5a6 6 0 0 1 12 0V20M17 5a3.5 3.5 0 0 1 0 6.8M18 14a5 5 0 0 1 3 4.5V20" />
            @break
        @case('employees')
            <rect x="4" y="4" width="16" height="16" rx="3" />
            <circle cx="12" cy="10" r="2.5" />
            <path d="M7.5 17a4.5 4.5 0 0 1 9 0" />
            @break
        @case('categories')
            <path d="m20 13-7 7-10-10V3h7l10 10Z" />
            <circle cx="7.5" cy="7.5" r="1" />
            @break
        @case('kanban')
            <rect x="3" y="4" width="5" height="16" rx="1.5" />
            <rect x="9.5" y="4" width="5" height="11" rx="1.5" />
            <rect x="16" y="4" width="5" height="13" rx="1.5" />
            @break
        @case('products')
            <path d="m3 7 9-4 9 4-9 4-9-4Z" />
            <path d="M3 7v10l9 4 9-4V7M12 11v10" />
            @break
        @case('alert')
            <path d="M12 8v5M12 17h.01" />
            <circle cx="12" cy="12" r="9" />
            @break
        @case('subcontractors')
            <path d="M4 8h6l2 2h8v9H4z" />
            <path d="M4 8V5h6l2 2M8 13h8M8 16h5" />
            @break
        @case('reports')
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
            @break
        @case('pin')
            <path d="m16 3 5 5-4 1-4 4v4l-2 2-2-6-6-2 2-2h4l4-4 1-2Z" />
            <path d="m9 15-5 5" />
            @break
        @case('chevron-down')
            <path d="m6 9 6 6 6-6" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @default
            <path d="M4 6h16M4 12h16M4 18h16" />
    @endswitch
</svg>
