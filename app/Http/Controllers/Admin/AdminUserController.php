<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        $admins = User::query()
            ->where('is_admin', true)
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('business_unit')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.users.index', compact('admins'));
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'admin' => new User,
            'isEditing' => false,
        ]);
    }

    public function show(User $user): View
    {
        $this->ensureAdminAccount($user);
        $menuOptions = User::menuOptionsForUnit($user->business_unit);
        $accessibleMenus = collect($menuOptions)
            ->only($user->accessible_menus ?? array_keys($menuOptions))
            ->values();

        return view('admin.users.show', [
            'admin' => $user,
            'accessibleMenus' => $accessibleMenus,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ...$this->accessValidationRules($request),
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $admin = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $admin->is_admin = true;
        $admin->role = User::ROLE_ADMIN;
        $admin->business_unit = $validated['business_unit'];
        $admin->accessible_units = [$validated['business_unit']];
        $admin->accessible_menus = array_values($validated['accessible_menus']);
        $admin->is_active = true;
        $admin->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Akun admin berhasil dibuat.');
    }

    public function edit(User $user): View
    {
        $this->ensureAdminAccount($user);

        return view('admin.users.form', [
            'admin' => $user,
            'isEditing' => true,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureAdminAccount($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            ...$this->accessValidationRules($request),
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->business_unit = $validated['business_unit'];
        $user->accessible_units = [$validated['business_unit']];
        $user->accessible_menus = array_values($validated['accessible_menus']);
        $user->is_active = (bool) $validated['is_active'];

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Akun admin berhasil diperbarui.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->ensureAdminAccount($user);
        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Akun admin berhasil dihapus.');
    }

    private function ensureAdminAccount(User $user): void
    {
        abort_unless($user->is_admin && $user->role === User::ROLE_ADMIN, 404);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function accessValidationRules(Request $request): array
    {
        $unit = $request->input('business_unit');
        $menus = User::menuOptionsForUnit(is_string($unit) ? $unit : null);

        return [
            'business_unit' => ['required', Rule::in([User::UNIT_BALI, User::UNIT_FACTORY])],
            'accessible_menus' => ['required', 'array', 'min:1'],
            'accessible_menus.*' => ['required', 'string', 'distinct', Rule::in(array_keys($menus))],
        ];
    }
}
