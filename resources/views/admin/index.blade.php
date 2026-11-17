@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">{{ $factoryDashboard ? 'Pilar Factory' : 'Ringkasan usaha' }}</p>
            <h1>{{ $factoryDashboard ? 'Dashboard Pilar Factory' : 'Dashboard' }}</h1>
            <p>{{ $factoryDashboard ? 'Pantau pekerjaan produksi dan akses menu operasional Factory.' : 'Kelola menu operasional unit yang tersedia untuk akun Anda.' }}</p>
        </div>
        <span class="dashboard-date">{{ now(auth()->user()->timezone())->format('d/m/Y') }} {{ auth()->user()->timezoneLabel() }}</span>
    </div>

    @if($factoryDashboard)
        <section class="welcome-panel">
            <div>
                <p class="page-eyebrow">Selamat datang di Pilar Factory</p>
                <h2>Ringkasan pekerjaan produksi.</h2>
                <p>Menu yang ditampilkan mengikuti izin akses akun Anda.</p>
            </div>
            <span class="welcome-panel-mark">
                <x-layout.icon name="dashboard" />
            </span>
        </section>

        @if($factoryStats !== [])
            <p class="dashboard-section-title">Ringkasan produksi</p>
            <section class="order-summary-grid" aria-label="Ringkasan produksi">
                @if(array_key_exists('active_orders', $factoryStats))
                    <article class="order-summary-card is-orders">
                        <span>Order berjalan</span>
                        <strong>{{ number_format($factoryStats['active_orders']) }}</strong>
                        <x-layout.icon name="orders" class="order-summary-icon" />
                    </article>
                @endif

                @if(array_key_exists('products_in_production', $factoryStats))
                    <article class="order-summary-card is-pending">
                        <span>Produk diproduksi</span>
                        <strong>{{ number_format($factoryStats['products_in_production']) }}</strong>
                        <x-layout.icon name="orders" class="order-summary-icon" />
                    </article>
                @endif
            </section>
        @endif

        <p class="dashboard-section-title">Menu utama</p>
        <div class="dashboard-grid">
            @if(auth()->user()->canAccessMenu('subcontractors'))
                <a class="dashboard-card" href="{{ route('subcontractors.index') }}">
                    <span class="dashboard-card-icon"><x-layout.icon name="subcontractors" /></span>
                    <span class="dashboard-card-copy">
                        <strong>Subkontraktor</strong>
                        <small>Kelola subcon dan pantau keberhasilan produksi</small>
                    </span>
                </a>
            @endif
            @if(auth()->user()->is_admin)
                <a class="dashboard-card" href="{{ route('kanban.index') }}">
                    <span class="dashboard-card-icon"><x-layout.icon name="kanban" /></span>
                    <span class="dashboard-card-copy">
                        <strong>Kanban</strong>
                        <small>Atur tugas admin dengan drag and drop</small>
                    </span>
                </a>
            @endif
            @if(auth()->user()->canAccessMenu('orders'))
                <a class="dashboard-card" href="{{ route('orders.index') }}">
                    <span class="dashboard-card-icon"><x-layout.icon name="orders" /></span>
                    <span class="dashboard-card-copy">
                        <strong>Daftar Order</strong>
                        <small>Lihat pesanan yang perlu diproduksi</small>
                    </span>
                </a>
            @endif
            @if(auth()->user()->canAccessMenu('order_products'))
                <a class="dashboard-card" href="{{ route('order-products.index') }}">
                    <span class="dashboard-card-icon"><x-layout.icon name="orders" /></span>
                    <span class="dashboard-card-copy">
                        <strong>Produk Orderan</strong>
                        <small>Lihat daftar produk tahap produksi</small>
                    </span>
                </a>
            @endif
            @if(auth()->user()->canAccessMenu('calendar'))
                <a class="dashboard-card" href="{{ route('calendar.index') }}">
                    <span class="dashboard-card-icon"><x-layout.icon name="calendar" /></span>
                    <span class="dashboard-card-copy">
                        <strong>Kalender Order</strong>
                        <small>Pantau jadwal dan perubahan status</small>
                    </span>
                </a>
            @endif
            @if(auth()->user()->canAccessMenu('employees'))
                <a class="dashboard-card" href="{{ route('admin.employees.index') }}">
                    <span class="dashboard-card-icon"><x-layout.icon name="employees" /></span>
                    <span class="dashboard-card-copy">
                        <strong>Karyawan</strong>
                        <small>Kelola data karyawan Factory</small>
                    </span>
                </a>
            @endif
            @if(auth()->user()->canAccessMenu('attendance'))
                <a class="dashboard-card" href="{{ route('admin.attendance.index') }}">
                    <span class="dashboard-card-icon"><x-layout.icon name="employees" /></span>
                    <span class="dashboard-card-copy">
                        <strong>Absensi</strong>
                        <small>Catat kehadiran karyawan Factory</small>
                    </span>
                </a>
            @endif
        </div>
    @else
    <section class="welcome-panel">
        <div>
            <p class="page-eyebrow">Selamat datang</p>
            <h2>Semua operasional dimulai di sini.</h2>
            <p>Pilih menu untuk melihat dan mengelola aktivitas usaha Anda.</p>
        </div>
        <span class="welcome-panel-mark">
            <x-layout.icon name="dashboard" />
        </span>
    </section>

    @if($orderStats)
        <p class="dashboard-section-title">Ringkasan order</p>
        <section class="order-summary-grid" aria-label="Ringkasan order">
            @if(isset($orderStats->total_revenue))
                <article class="order-summary-card is-revenue">
                    <span>Total Pendapatan</span>
                    <strong>Rp {{ number_format($orderStats->total_revenue, 0, '.', ',') }}</strong>
                    <x-layout.icon name="reports" class="order-summary-icon" />
                </article>
            @endif
            <article class="order-summary-card is-orders">
                <span>Total Orders</span>
                <strong>{{ number_format($orderStats->total_orders ?? 0) }}</strong>
                <x-layout.icon name="orders" class="order-summary-icon" />
            </article>
            <article class="order-summary-card is-pending">
                <span>Pending</span>
                <strong>{{ number_format($orderStats->pending_orders ?? 0) }}</strong>
                <x-layout.icon name="calendar" class="order-summary-icon" />
            </article>
            <article class="order-summary-card is-completed">
                <span>Selesai</span>
                <strong>{{ number_format($orderStats->completed_orders ?? 0) }}</strong>
                <x-layout.icon name="dashboard" class="order-summary-icon" />
            </article>
        </section>
    @endif

    @if(auth()->user()->canAccessMenu('guests'))
        <section class="order-list-panel dashboard-debtor-panel">
            <header class="dashboard-debtor-header">
                <div>
                    <p class="page-eyebrow">Relasi pelanggan</p>
                    <h2>Tamu dengan hutang terlama</h2>
                </div>
                <a class="btn" href="{{ route('guests.index') }}">Lihat semua tamu</a>
            </header>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Tamu</th>
                            <th>Hutang sejak</th>
                            <th>Total hutang</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topDebtorGuests as $guest)
                            <tr>
                                <td><a href="{{ route('guests.show', $guest) }}">{{ $guest->name }}</a></td>
                                <td>{{ $guest->oldest_debt_at->format('d/m/Y') }}</td>
                                <td class="guest-debt-amount">Rp {{ number_format((float) $guest->debt_amount, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">Belum ada tamu dengan hutang tertunggak.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <p class="dashboard-section-title">Menu utama</p>

    <div class="dashboard-grid">
        @if(auth()->user()->canAccessMenu('subcontractors'))
            <a class="dashboard-card" href="{{ route('subcontractors.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="subcontractors" /></span>
                <span class="dashboard-card-copy">
                    <strong>Subkontraktor</strong>
                    <small>Kelola subcon dan pantau keberhasilan produksi</small>
                </span>
            </a>
        @endif
        @if(auth()->user()->is_admin)
            <a class="dashboard-card" href="{{ route('kanban.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="kanban" /></span>
                <span class="dashboard-card-copy">
                    <strong>Kanban</strong>
                    <small>Atur tugas admin dengan drag and drop</small>
                </span>
            </a>
        @endif
        @if(auth()->user()->canAccessMenu('orders'))
            <a class="dashboard-card" href="{{ route('orders.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="orders" /></span>
                <span class="dashboard-card-copy">
                    <strong>Daftar Order</strong>
                    <small>Lihat dan kelola pesanan pelanggan</small>
                </span>
            </a>
        @endif
        @if(auth()->user()->canAccessMenu('calendar'))
            <a class="dashboard-card" href="{{ route('calendar.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="calendar" /></span>
                <span class="dashboard-card-copy">
                    <strong>Kalender Order</strong>
                    <small>Pantau order masuk, target kirim, dan perubahan status</small>
                </span>
            </a>
        @endif
        @if(auth()->user()->canAccessMenu('order_products'))
            <a class="dashboard-card" href="{{ route('order-products.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="orders" /></span>
                <span class="dashboard-card-copy">
                    <strong>Produk Orderan</strong>
                    <small>Lihat produk sesuai tahap kerja unit</small>
                </span>
            </a>
        @endif

        @if(auth()->user()->canAccessMenu('guests'))
            <a class="dashboard-card" href="{{ route('guests.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="guests" /></span>
                <span class="dashboard-card-copy">
                    <strong>Daftar Tamu</strong>
                    <small>Data pelanggan dan riwayat pembelian</small>
                </span>
            </a>
        @endif

        @if(auth()->user()->canAccessMenu('employees'))
            <a class="dashboard-card" href="{{ route('admin.employees.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="employees" /></span>
                <span class="dashboard-card-copy">
                    <strong>Karyawan</strong>
                    <small>Kelola data karyawan</small>
                </span>
            </a>
        @endif

        @if(auth()->user()->canAccessMenu('attendance'))
            <a class="dashboard-card" href="{{ route('admin.attendance.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="employees" /></span>
                <span class="dashboard-card-copy">
                    <strong>Absensi</strong>
                    <small>Catat kehadiran dan lembur harian</small>
                </span>
            </a>
        @endif

        @if(auth()->user()->canAccessMenu('categories'))
            <a class="dashboard-card" href="{{ route('categories.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="categories" /></span>
                <span class="dashboard-card-copy">
                    <strong>Kategori Produk</strong>
                    <small>Atur kategori untuk setiap produk</small>
                </span>
            </a>

        @endif

        @if(auth()->user()->canAccessMenu('popular_categories'))
            <a class="dashboard-card" href="{{ route('reports.popular-categories') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="reports" /></span>
                <span class="dashboard-card-copy">
                    <strong>Kategori Terlaris</strong>
                    <small>Lihat penjualan produk per kategori</small>
                </span>
            </a>
        @endif

        @if(auth()->user()->isOwner())
            <a class="dashboard-card" href="{{ route('admin.users.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="employees" /></span>
                <span class="dashboard-card-copy">
                    <strong>Akun Admin</strong>
                    <small>Kelola akses admin Pilar Bali dan Factory</small>
                </span>
            </a>
            <a class="dashboard-card" href="{{ route('admin.activity-log.index') }}">
                <span class="dashboard-card-icon"><x-layout.icon name="reports" /></span>
                <span class="dashboard-card-copy">
                    <strong>Activity Log</strong>
                    <small>Periksa aktivitas admin setiap unit</small>
                </span>
            </a>
        @endif
    </div>
    @endif
@endsection
