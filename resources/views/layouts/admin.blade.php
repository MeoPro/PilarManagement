<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Pilar Management') | Pilar Management</title>
    @vite(['resources/css/app.css', 'resources/js/spa-navigation.js', 'resources/js/app.js'])
</head>
<body>
    <div class="navigation-progress" role="status" aria-label="Memuat halaman" hidden>
        <span aria-hidden="true"></span>
    </div>

    @php
        $user = auth()->user();
        $activeBusinessUnit = $user->activeBusinessUnit();
        $navigationGroups = [
            'Operasional' => [],
            'Absen' => [],
            'Orderan' => [],
            'SDM & Relasi' => [],
            'Finance' => [],
            'Analisis' => [],
            'Pengaturan' => [],
        ];
        $unitMenus = [
            'calendar' => ['group' => 'Operasional', 'label' => 'Kalender Order', 'route' => 'calendar.index', 'icon' => 'calendar', 'active' => 'calendar.index'],
            'orders' => ['group' => 'Orderan', 'label' => 'Daftar Order', 'route' => 'orders.index', 'icon' => 'orders', 'active' => 'orders.*'],
            'revenue' => ['group' => 'Finance', 'label' => 'Omzet', 'route' => 'revenue.index', 'icon' => 'reports', 'active' => 'revenue.*'],
            'products' => ['group' => 'Operasional', 'label' => 'Katalog Produk', 'route' => 'products.index', 'icon' => 'products', 'active' => 'products.*'],
            'product_stock' => ['group' => 'Operasional', 'label' => 'Stock Produk', 'route' => 'product-stock.index', 'icon' => 'products', 'active' => 'product-stock.*'],
            'order_products' => ['group' => 'Orderan', 'label' => 'Produk Orderan', 'route' => 'order-products.index', 'icon' => 'orders', 'active' => 'order-products.*'],
            'subcontractor_products' => ['group' => 'Operasional', 'label' => 'Produk Subcon', 'route' => 'subcontractor-products.index', 'icon' => 'subcontractors', 'active' => 'subcontractor-products.*'],
            'subcontractors' => ['group' => 'Operasional', 'label' => 'Subkontraktor', 'route' => 'subcontractors.index', 'icon' => 'subcontractors', 'active' => 'subcontractors.*'],
            'materials' => ['group' => 'Operasional', 'label' => 'Material', 'route' => 'materials.index', 'icon' => 'categories', 'active' => 'materials.*'],
            'expenses' => ['group' => 'Finance', 'label' => 'Pengeluaran', 'route' => 'expenses.index', 'icon' => 'reports', 'active' => 'expenses.*'],
            'employees' => ['group' => 'SDM & Relasi', 'label' => 'Karyawan', 'route' => 'admin.employees.index', 'icon' => 'employees', 'active' => 'admin.employees.*'],
            'guests' => ['group' => 'SDM & Relasi', 'label' => 'Daftar Tamu', 'route' => 'guests.index', 'icon' => 'guests', 'active' => 'guests.*'],
            'performance' => ['group' => 'Analisis', 'label' => 'Peforma', 'route' => 'admin.performance.index', 'icon' => 'reports', 'active' => 'admin.performance.*'],
        ];

        foreach ($unitMenus as $key => $menu) {
            if ($user->isOwner()
                && ! array_key_exists($key, \App\Models\User::menuOptionsForUnit($activeBusinessUnit))) {
                continue;
            }

            if ($user->canAccessMenu($key)) {
                $group = $menu['group'];
                unset($menu['group']);
                $navigationGroups[$group][] = $menu + ['key' => $key, 'permission' => $key];
            }
        }

        if ($user->canAccessMenu('attendance')) {
            $navigationGroups['Absen'][] = [
                'key' => 'attendance_daily',
                'permission' => 'attendance',
                'label' => 'Absensi Harian',
                'route' => 'admin.attendance.index',
                'parameters' => ['work_type' => 'Harian'],
                'icon' => 'employees',
                'active' => 'admin.attendance.index',
                'active_query' => ['work_type' => 'Harian'],
            ];
            $navigationGroups['Absen'][] = [
                'key' => 'attendance_piecework',
                'permission' => 'attendance',
                'label' => 'Absensi Borongan',
                'route' => 'admin.attendance.index',
                'parameters' => ['work_type' => 'Borongan'],
                'icon' => 'employees',
                'active' => 'admin.attendance.index',
                'active_query' => ['work_type' => 'Borongan'],
            ];
        }

        if ($user->canAccessMenu('employees')) {
            $navigationGroups['Finance'][] = [
                'key' => 'payroll',
                'permission' => 'employees',
                'label' => 'Gaji',
                'route' => 'admin.payroll.index',
                'icon' => 'reports',
                'active' => 'admin.payroll.index',
                'children' => [
                    ['label' => 'Gaji Harian', 'route' => 'admin.payroll.index', 'parameters' => ['salary_type' => 'Harian']],
                    ['label' => 'Gaji Borongan', 'route' => 'admin.payroll.index', 'parameters' => ['salary_type' => 'Borongan']],
                ],
            ];
            $navigationGroups['Finance'][] = [
                'key' => 'cashbond',
                'permission' => 'employees',
                'label' => 'Cashbond',
                'route' => 'admin.cashbond.index',
                'icon' => 'reports',
                'active' => 'admin.cashbond.*',
            ];
        }

        if ($user->is_admin) {
            $navigationGroups['Operasional'][] = [
                'key' => 'kanban',
                'permission' => null,
                'label' => 'Kanban',
                'route' => 'kanban.index',
                'icon' => 'kanban',
                'active' => 'kanban.*',
                'unread' => $unreadKanbanTaskCount ?? 0,
            ];
        }

        $analysisMenus = [
            'categories' => ['label' => 'Kategori Produk', 'route' => 'categories.index', 'icon' => 'categories', 'active' => 'categories.*'],
            'popular_categories' => ['label' => 'Kategori Terlaris', 'route' => 'reports.popular-categories', 'icon' => 'reports', 'active' => 'reports.popular-categories'],
        ];

        foreach ($analysisMenus as $key => $menu) {
            if ($user->canAccessMenu($key)) {
                $navigationGroups['Analisis'][] = $menu + ['key' => $key, 'permission' => $key];
            }
        }

        if (empty($navigationGroups['Analisis'])) {
            unset($navigationGroups['Analisis']);
        }

        if ($user->isOwner()) {
            $navigationGroups['Pengaturan'] = [
                ['key' => 'admin_users', 'permission' => null, 'label' => 'Akun Admin', 'route' => 'admin.users.index', 'icon' => 'employees', 'active' => 'admin.users.*'],
                ['key' => 'attendance_schedule', 'permission' => null, 'label' => 'Jadwal Absensi', 'route' => 'admin.attendance-schedule.edit', 'icon' => 'calendar', 'active' => 'admin.attendance-schedule.*'],
                ['key' => 'activity_log', 'permission' => null, 'label' => 'Activity Log', 'route' => 'admin.activity-log.index', 'icon' => 'reports', 'active' => 'admin.activity-log.*'],
            ];
        }
        $navigationGroups['Pengaturan'][] = [
            'key' => 'sidebar_preferences',
            'permission' => null,
            'label' => 'Urutan Sidebar',
            'route' => 'admin.sidebar-preferences.edit',
            'icon' => 'menu',
            'active' => 'admin.sidebar-preferences.*',
            'pinnable' => false,
        ];

        $pinnedMenuKeys = array_values(array_unique($user->pinned_menus ?? []));
        $pinnedItems = [];
        foreach ($navigationGroups as $group => $items) {
            $navigationGroups[$group] = array_values(array_filter($items, function (array $item) use ($pinnedMenuKeys, &$pinnedItems): bool {
                if (! in_array($item['key'], $pinnedMenuKeys, true)) {
                    return true;
                }

                $pinnedItems[] = $item;

                return false;
            }));
        }

        foreach ($navigationGroups as $group => $items) {
            if ($items === []) {
                unset($navigationGroups[$group]);
            }
        }

        $sidebarOrderPositions = array_flip(array_values(array_unique($user->sidebar_order ?? [])));
        $defaultGroupPositions = array_flip(array_keys($navigationGroups));
        $sidebarGroupPositions = [];
        foreach ($navigationGroups as $group => $items) {
            $positions = array_map(
                fn (array $item): int => $sidebarOrderPositions[$item['key']] ?? PHP_INT_MAX,
                $items,
            );
            $sidebarGroupPositions[$group] = min($positions);
        }
        uksort($navigationGroups, fn (string $first, string $second): int => (
            $sidebarGroupPositions[$first] <=> $sidebarGroupPositions[$second]
        ) ?: ($defaultGroupPositions[$first] <=> $defaultGroupPositions[$second]));

        if ($pinnedItems !== []) {
            $navigationGroups = ['Disematkan' => $pinnedItems] + $navigationGroups;
        }
        foreach ($navigationGroups as &$items) {
            usort($items, fn (array $first, array $second): int => ($sidebarOrderPositions[$first['key']] ?? PHP_INT_MAX)
                <=> ($sidebarOrderPositions[$second['key']] ?? PHP_INT_MAX));
        }
        unset($items);
    @endphp

    <div class="app-shell">
        <aside class="app-sidebar">
            <a class="brand" href="{{ route('admin.index') }}">
                <span class="brand-mark">P</span>
                <span class="brand-name">{{ $user->workspaceName() }}</span>
            </a>

            <nav class="sidebar-navigation" aria-label="Navigasi utama">
                @foreach($navigationGroups as $group => $items)
                    <div class="navigation-group">
                        <p class="navigation-label">{{ $group }}</p>
                        @foreach($items as $item)
                            @php($active = request()->routeIs($item['active']) && (!isset($item['active_query']) || request()->query(array_key_first($item['active_query'])) === array_values($item['active_query'])[0]))
                            @php($isPinned = in_array($item['key'], $pinnedMenuKeys, true))
                            <div class="navigation-item">
                                @if(!empty($item['children']))
                                    <details class="navigation-submenu" @if($active) open @endif>
                                        <summary class="navigation-link {{ $active ? 'is-active' : '' }}">
                                            <x-layout.icon :name="$item['icon']" />
                                            <span>{{ $item['label'] }}</span>
                                            <x-layout.icon name="chevron-down" class="navigation-submenu-indicator" />
                                        </summary>
                                        <div class="navigation-submenu-links">
                                            @foreach($item['children'] as $child)
                                                @php($childActive = request()->routeIs($child['route']) && request()->query('salary_type') === $child['parameters']['salary_type'])
                                                <a class="navigation-link {{ $childActive ? 'is-active' : '' }}" href="{{ route($child['route'], $child['parameters']) }}">
                                                    <span>{{ $child['label'] }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </details>
                                @else
                                    <a
                                        class="navigation-link {{ $active ? 'is-active' : '' }}"
                                        href="{{ route($item['route'], $item['parameters'] ?? []) }}"
                                        @if($active) aria-current="page" @endif
                                    >
                                        <x-layout.icon :name="$item['icon']" />
                                        <span>{{ $item['label'] }}</span>
                                        @if(($item['unread'] ?? 0) > 0)
                                            <span
                                                class="kanban-notification-indicator"
                                                title="Ada tugas baru dari Owner"
                                                aria-label="{{ $item['unread'] }} tugas baru dari Owner"
                                            >
                                                <x-layout.icon name="alert" />
                                                <span aria-hidden="true">{{ $item['unread'] > 99 ? '99+' : $item['unread'] }}</span>
                                            </span>
                                        @endif
                                    </a>
                                @endif
                                @if($item['pinnable'] ?? true)
                                    <form action="{{ route('admin.menu-preferences.update') }}" method="POST" class="navigation-pin-form" data-spa-submit>
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="menu" value="{{ $item['key'] }}">
                                        <input type="hidden" name="pinned" value="{{ $isPinned ? '0' : '1' }}">
                                        <button
                                            class="navigation-pin-button"
                                            type="submit"
                                            aria-label="{{ $isPinned ? 'Lepas sematan '.$item['label'] : 'Sematkan '.$item['label'] }}"
                                            aria-pressed="{{ $isPinned ? 'true' : 'false' }}"
                                            title="{{ $isPinned ? 'Lepas dari menu teratas' : 'Sematkan di menu teratas' }}"
                                        >
                                            <x-layout.icon name="pin" />
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="sidebar-workspace">
                <span class="workspace-indicator"></span>
                <span><strong>{{ $user->isOwner() ? $user->activeBusinessUnitLabel() : $user->businessUnitLabel() }}</strong><small>Panel operasional</small></span>
            </div>
        </aside>

        <div class="app-main">
            <header class="app-topbar">
                <details class="mobile-menu">
                    <summary aria-label="Buka navigasi">
                        <x-layout.icon name="menu" />
                    </summary>
                    <nav class="mobile-menu-panel" aria-label="Navigasi mobile">
                        @foreach($navigationGroups as $group => $items)
                            <div class="navigation-group">
                                <p class="navigation-label">{{ $group }}</p>
                                @foreach($items as $item)
                                    @php($active = request()->routeIs($item['active']) && (!isset($item['active_query']) || request()->query(array_key_first($item['active_query'])) === array_values($item['active_query'])[0]))
                                    @php($isPinned = in_array($item['key'], $pinnedMenuKeys, true))
                                    <div class="navigation-item">
                                        @if(!empty($item['children']))
                                            <details class="navigation-submenu" @if($active) open @endif>
                                                <summary class="navigation-link {{ $active ? 'is-active' : '' }}">
                                                    <x-layout.icon :name="$item['icon']" />
                                                    <span>{{ $item['label'] }}</span>
                                                    <x-layout.icon name="chevron-down" class="navigation-submenu-indicator" />
                                                </summary>
                                                <div class="navigation-submenu-links">
                                                    @foreach($item['children'] as $child)
                                                        @php($childActive = request()->routeIs($child['route']) && request()->query('salary_type') === $child['parameters']['salary_type'])
                                                        <a class="navigation-link {{ $childActive ? 'is-active' : '' }}" href="{{ route($child['route'], $child['parameters']) }}">
                                                            <span>{{ $child['label'] }}</span>
                                                        </a>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @else
                                            <a
                                                class="navigation-link {{ $active ? 'is-active' : '' }}"
                                                href="{{ route($item['route'], $item['parameters'] ?? []) }}"
                                                @if($active) aria-current="page" @endif
                                            >
                                                <x-layout.icon :name="$item['icon']" />
                                                <span>{{ $item['label'] }}</span>
                                                @if(($item['unread'] ?? 0) > 0)
                                                    <span
                                                        class="kanban-notification-indicator"
                                                        title="Ada tugas baru dari Owner"
                                                        aria-label="{{ $item['unread'] }} tugas baru dari Owner"
                                                    >
                                                        <x-layout.icon name="alert" />
                                                        <span aria-hidden="true">{{ $item['unread'] > 99 ? '99+' : $item['unread'] }}</span>
                                                    </span>
                                                @endif
                                            </a>
                                        @endif
                                        @if($item['pinnable'] ?? true)
                                            <form action="{{ route('admin.menu-preferences.update') }}" method="POST" class="navigation-pin-form" data-spa-submit>
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="menu" value="{{ $item['key'] }}">
                                                <input type="hidden" name="pinned" value="{{ $isPinned ? '0' : '1' }}">
                                                <button
                                                    class="navigation-pin-button"
                                                    type="submit"
                                                    aria-label="{{ $isPinned ? 'Lepas sematan '.$item['label'] : 'Sematkan '.$item['label'] }}"
                                                    aria-pressed="{{ $isPinned ? 'true' : 'false' }}"
                                                    title="{{ $isPinned ? 'Lepas dari menu teratas' : 'Sematkan di menu teratas' }}"
                                                >
                                                    <x-layout.icon name="pin" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </nav>
                </details>

                <div class="topbar-context">
                    <span class="topbar-kicker">PILAR MANAGEMENT</span>
                    <strong>@yield('title', 'Dashboard')</strong>
                </div>

                <div class="topbar-actions">
                    @if($user->isOwner())
                        <form action="{{ route('admin.business-unit-preferences.update') }}" method="POST" class="unit-focus-form" data-spa-submit>
                            @csrf
                            @method('PUT')
                            <button
                                type="submit"
                                class="unit-focus-toggle"
                                name="business_unit"
                                value="{{ $activeBusinessUnit === \App\Models\User::UNIT_BALI ? \App\Models\User::UNIT_FACTORY : \App\Models\User::UNIT_BALI }}"
                                role="switch"
                                aria-label="Fokus unit"
                                aria-checked="{{ $activeBusinessUnit === \App\Models\User::UNIT_FACTORY ? 'true' : 'false' }}"
                                data-unit-focus-toggle
                                title="Beralih ke {{ $activeBusinessUnit === \App\Models\User::UNIT_BALI ? 'PF' : 'PB' }}"
                            >
                                <span class="unit-focus-toggle-label">PB</span>
                                <span class="unit-focus-toggle-label">PF</span>
                                <span class="unit-focus-toggle-thumb" aria-hidden="true"></span>
                            </button>
                        </form>
                        <form action="{{ route('admin.timezone-preferences.update') }}" method="POST" class="timezone-preference-form" data-spa-submit>
                            @csrf
                            @method('PUT')
                            <label class="sr-only" for="timezone-preference">Zona waktu</label>
                            <select id="timezone-preference" name="timezone_preference" aria-label="Zona waktu">
                                <option value="Asia/Makassar" @selected($user->timezone() === 'Asia/Makassar')>WITA</option>
                                <option value="Asia/Jakarta" @selected($user->timezone() === 'Asia/Jakarta')>WIB</option>
                            </select>
                            <button type="submit" class="timezone-label">{{ $user->timezoneLabel() }}</button>
                        </form>
                    @else
                        <span class="timezone-label">{{ $user->timezoneLabel() }}</span>
                    @endif
                    <div class="topbar-user">
                        <span class="user-avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                        <span class="topbar-user-name">{{ auth()->user()->name }} · {{ $user->isOwner() ? $user->activeBusinessUnitLabel() : $user->businessUnitLabel() }}</span>
                    </div>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button class="logout-button" type="submit">Keluar</button>
                    </form>
                </div>
            </header>

            <main class="page-container">
                @yield('content')
            </main>

            <footer class="app-footer">
                <span>&copy; {{ now($user->timezone())->year }} Pilar Management</span>
                <span>
                    Designed &amp; developed by
                    <a href="https://datadropx.net" target="_blank" rel="noopener noreferrer">DataDropX</a>
                </span>
            </footer>
        </div>
    </div>

    @if($user->canAccessMenu('orders'))
        <div
            id="order-comment-reminder"
            class="order-chat-reminder"
            data-reminders-url="{{ route('order-comments.reminders') }}"
            hidden
        >
            <section class="order-chat-reminder-dialog" role="dialog" aria-modal="true" aria-labelledby="order-chat-reminder-title">
                <header>
                    <div>
                        <p class="page-eyebrow">Percakapan order</p>
                        <h2 id="order-chat-reminder-title">Chat belum dibaca</h2>
                    </div>
                    <button type="button" class="icon-button" data-dismiss-reminders aria-label="Tutup notifikasi">×</button>
                </header>
                <p class="muted">Ada pesan dari unit lain yang belum dibaca selama 2 hari.</p>
                <ul class="order-chat-reminder-list" data-reminder-list></ul>
                <button type="button" class="btn" data-dismiss-reminders>Tutup</button>
            </section>
        </div>
    @endif
</body>
</html>
