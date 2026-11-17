<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserMenuPreferenceController extends Controller
{
    private const SIDEBAR_MENU_ITEMS = [
        'calendar' => ['group' => 'Operasional', 'label' => 'Kalender Order', 'permission' => 'calendar', 'unit_limited' => true],
        'orders' => ['group' => 'Orderan', 'label' => 'Daftar Order', 'permission' => 'orders', 'unit_limited' => true],
        'products' => ['group' => 'Operasional', 'label' => 'Katalog Produk', 'permission' => 'products', 'unit_limited' => true],
        'order_products' => ['group' => 'Orderan', 'label' => 'Produk Orderan', 'permission' => 'order_products', 'unit_limited' => true],
        'subcontractor_products' => ['group' => 'Operasional', 'label' => 'Produk Subcon', 'permission' => 'subcontractors', 'unit_limited' => true],
        'subcontractors' => ['group' => 'Operasional', 'label' => 'Subkontraktor', 'permission' => 'subcontractors', 'unit_limited' => true],
        'materials' => ['group' => 'Operasional', 'label' => 'Material', 'permission' => 'materials', 'unit_limited' => true],
        'expenses' => ['group' => 'Finance', 'label' => 'Pengeluaran', 'permission' => 'expenses', 'unit_limited' => true],
        'employees' => ['group' => 'SDM & Relasi', 'label' => 'Karyawan', 'permission' => 'employees', 'unit_limited' => true],
        'guests' => ['group' => 'SDM & Relasi', 'label' => 'Daftar Tamu', 'permission' => 'guests', 'unit_limited' => true],
        'performance' => ['group' => 'Analisis', 'label' => 'Peforma', 'permission' => 'performance', 'unit_limited' => true],
        'attendance_daily' => ['group' => 'Absen', 'label' => 'Absensi Harian', 'permission' => 'attendance'],
        'attendance_piecework' => ['group' => 'Absen', 'label' => 'Absensi Borongan', 'permission' => 'attendance'],
        'payroll' => ['group' => 'Finance', 'label' => 'Gaji', 'permission' => 'employees'],
        'cashbond' => ['group' => 'Finance', 'label' => 'Cashbond', 'permission' => 'employees'],
        'kanban' => ['group' => 'Operasional', 'label' => 'Kanban', 'admin_only' => true],
        'categories' => ['group' => 'Analisis', 'label' => 'Kategori Produk', 'permission' => 'categories'],
        'popular_categories' => ['group' => 'Analisis', 'label' => 'Kategori Terlaris', 'permission' => 'popular_categories'],
        'admin_users' => ['group' => 'Pengaturan', 'label' => 'Akun Admin', 'owner_only' => true],
        'activity_log' => ['group' => 'Pengaturan', 'label' => 'Activity Log', 'owner_only' => true],
        'attendance_schedule' => ['group' => 'Pengaturan', 'label' => 'Jadwal Absensi', 'owner_only' => true],
    ];

    private const MENU_PERMISSIONS = [
        'calendar' => 'calendar',
        'orders' => 'orders',
        'products' => 'products',
        'order_products' => 'order_products',
        'subcontractor_products' => 'subcontractors',
        'subcontractors' => 'subcontractors',
        'materials' => 'materials',
        'expenses' => 'expenses',
        'employees' => 'employees',
        'performance' => 'performance',
        'attendance_daily' => 'attendance',
        'attendance_piecework' => 'attendance',
        'payroll' => 'employees',
        'payroll_daily' => 'employees',
        'payroll_piecework' => 'employees',
        'cashbond' => 'employees',
        'guests' => 'guests',
        'kanban' => null,
        'categories' => 'categories',
        'popular_categories' => 'popular_categories',
        'admin_users' => null,
        'activity_log' => null,
        'attendance_schedule' => null,
    ];

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'menu' => ['required', Rule::in(array_keys(self::MENU_PERMISSIONS))],
            'pinned' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $menu = $validated['menu'];
        $permission = self::MENU_PERMISSIONS[$menu];

        if ($menu === 'kanban') {
            abort_unless($user->is_admin, 403);
        } elseif ($permission === null) {
            abort_unless($user->isOwner(), 403);
        } else {
            abort_unless($user->canAccessMenu($permission), 403);
        }

        $pinnedMenus = array_values($user->pinned_menus ?? []);
        $pinnedMenus = array_values(array_diff($pinnedMenus, [$menu]));

        if ($request->boolean('pinned')) {
            $pinnedMenus[] = $menu;
        }

        $user->forceFill(['pinned_menus' => $pinnedMenus])->save();

        return back()->with('success', $request->boolean('pinned')
            ? 'Menu berhasil disematkan di bagian atas.'
            : 'Sematan menu berhasil dilepas.');
    }

    public function editSidebar(Request $request): View
    {
        $menuItems = $this->availableSidebarMenuItems($request->user());
        $savedOrder = array_values(array_intersect(
            $request->user()->sidebar_order ?? [],
            array_keys($menuItems),
        ));
        $defaultPositions = array_flip(array_keys($menuItems));
        $savedPositions = array_flip($savedOrder);

        foreach ($menuItems as $key => &$item) {
            $item['key'] = $key;
            $item['position'] = ($savedPositions[$key] ?? $defaultPositions[$key]) + 1;
        }
        unset($item);

        $menuItems = collect($menuItems)
            ->sortBy('position')
            ->groupBy('group')
            ->all();

        return view('admin.preferences.sidebar', [
            'sidebarMenuGroups' => $menuItems,
            'menuCount' => count($defaultPositions),
        ]);
    }

    public function updateSidebar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $menuItems = $this->availableSidebarMenuItems($user);
        $menuCount = count($menuItems);
        $validated = $request->validate([
            'menu_order' => ['required', 'array'],
            'menu_order.*' => ['required', 'integer', 'min:1', 'max:'.$menuCount, 'distinct'],
        ]);

        $submittedKeys = array_keys($validated['menu_order']);
        $allowedKeys = array_keys($menuItems);
        sort($submittedKeys);
        sort($allowedKeys);
        if ($submittedKeys !== $allowedKeys) {
            throw ValidationException::withMessages([
                'menu_order' => 'Urutan harus berisi seluruh menu yang dapat Anda akses.',
            ]);
        }

        asort($validated['menu_order'], SORT_NUMERIC);
        $orderedMenus = array_keys($validated['menu_order']);
        $hiddenMenus = array_values(array_diff($user->sidebar_order ?? [], $allowedKeys));
        $user->forceFill([
            'sidebar_order' => array_merge($orderedMenus, $hiddenMenus),
        ])->save();

        return redirect()
            ->route('admin.sidebar-preferences.edit')
            ->with('success', 'Urutan sidebar berhasil disimpan.');
    }

    public function updateTimezone(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);

        $validated = $request->validate([
            'timezone_preference' => ['required', Rule::in(['Asia/Jakarta', 'Asia/Makassar'])],
        ]);

        $request->user()->forceFill($validated)->save();

        return back()->with('success', 'Zona waktu berhasil diperbarui.');
    }

    public function updateBusinessUnit(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);

        $validated = $request->validate([
            'business_unit' => ['required', Rule::in([User::UNIT_BALI, User::UNIT_FACTORY])],
        ]);

        $request->session()->put('active_business_unit', $validated['business_unit']);

        return back()
            ->with('success', 'Fokus unit berhasil diubah ke '.$request->user()->activeBusinessUnitLabel().'.');
    }

    /**
     * @return array<string, array{group: string, label: string, permission?: string, unit_limited?: bool, admin_only?: bool, owner_only?: bool}>
     */
    private function availableSidebarMenuItems(User $user): array
    {
        $unitMenus = User::menuOptionsForUnit($user->activeBusinessUnit());

        return array_filter(self::SIDEBAR_MENU_ITEMS, function (array $item, string $key) use ($user, $unitMenus): bool {
            if (($item['owner_only'] ?? false) && ! $user->isOwner()) {
                return false;
            }

            if (($item['admin_only'] ?? false) && ! $user->is_admin) {
                return false;
            }

            if ($user->isOwner()
                && ($item['unit_limited'] ?? false)
                && ! array_key_exists($key, $unitMenus)) {
                return false;
            }

            return ! isset($item['permission']) || $user->canAccessMenu($item['permission']);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
