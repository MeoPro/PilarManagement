<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const UNIT_BALI = 'bali';

    public const UNIT_FACTORY = 'factory';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'accessible_units' => 'array',
            'accessible_menus' => 'array',
            'pinned_menus' => 'array',
            'sidebar_order' => 'array',
        ];
    }

    public function isOwner(): bool
    {
        return $this->is_admin && $this->role === self::ROLE_OWNER;
    }

    public function isFactoryAdmin(): bool
    {
        return $this->is_admin
            && $this->role === self::ROLE_ADMIN
            && $this->canAccessUnit(self::UNIT_FACTORY);
    }

    public function isFactoryWorkflow(): bool
    {
        return $this->isFactoryAdmin()
            || ($this->isOwner() && $this->activeBusinessUnit() === self::UNIT_FACTORY);
    }

    public function isSalesWorkflow(): bool
    {
        return $this->canManageSales()
            && (! $this->isOwner() || $this->activeBusinessUnit() === self::UNIT_BALI);
    }

    public function activeBusinessUnit(): ?string
    {
        if ($this->isOwner()) {
            $selectedUnit = session('active_business_unit', self::UNIT_BALI);

            return in_array($selectedUnit, [self::UNIT_BALI, self::UNIT_FACTORY], true)
                ? $selectedUnit
                : self::UNIT_BALI;
        }

        return $this->accessibleUnits()[0] ?? null;
    }

    public function activeBusinessUnitLabel(): string
    {
        return $this->activeBusinessUnit() === self::UNIT_FACTORY
            ? 'Pilar Factory'
            : 'Pilar Bali';
    }

    public function canManageSales(): bool
    {
        return $this->isOwner()
            || ($this->is_admin
                && $this->role === self::ROLE_ADMIN
                && $this->canAccessUnit(self::UNIT_BALI));
    }

    public function canAccessUnit(string $unit): bool
    {
        return $this->isOwner()
            || ($this->is_admin
                && $this->role === self::ROLE_ADMIN
                && in_array($unit, $this->accessibleUnits(), true));
    }

    /**
     * @return list<string>
     */
    public function accessibleUnits(): array
    {
        if ($this->isOwner()) {
            return [self::UNIT_BALI, self::UNIT_FACTORY];
        }

        if (in_array($this->business_unit, [self::UNIT_BALI, self::UNIT_FACTORY], true)) {
            return [$this->business_unit];
        }

        return array_slice(array_values(array_intersect(
            array_unique($this->accessible_units ?? []),
            [self::UNIT_BALI, self::UNIT_FACTORY],
        )), 0, 1);
    }

    public function canSelectBusinessUnit(): bool
    {
        return false;
    }

    public function timezone(): string
    {
        if ($this->isOwner()) {
            return in_array($this->timezone_preference, ['Asia/Jakarta', 'Asia/Makassar'], true)
                ? $this->timezone_preference
                : 'Asia/Makassar';
        }

        return self::timezoneForUnit($this->accessibleUnits()[0] ?? null);
    }

    public function timezoneLabel(): string
    {
        return $this->timezone() === 'Asia/Jakarta' ? 'WIB' : 'WITA';
    }

    public static function timezoneForUnit(?string $unit): string
    {
        return $unit === self::UNIT_FACTORY ? 'Asia/Jakarta' : 'Asia/Makassar';
    }

    public function canAccessMenu(string $menu): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        $unit = $this->accessibleUnits()[0] ?? null;
        $menuOptions = self::menuOptionsForUnit($unit);

        if (! array_key_exists($menu, $menuOptions)) {
            return false;
        }

        $accessibleMenus = $this->accessible_menus;

        return $accessibleMenus === null || in_array($menu, $accessibleMenus, true);
    }

    public function kanbanTasks(): HasMany
    {
        return $this->hasMany(KanbanTask::class, 'assignee_id');
    }

    public function adminAttendances(): HasMany
    {
        return $this->hasMany(AdminAttendance::class);
    }

    public function createdKanbanTasks(): HasMany
    {
        return $this->hasMany(KanbanTask::class, 'created_by_id');
    }

    /**
     * @return array<string, string>
     */
    public static function menuOptionsForUnit(?string $unit): array
    {
        $sharedMenus = [
            'calendar' => 'Kalender Order',
            'orders' => 'Daftar Order',
            'revenue' => 'Omzet',
            'order_products' => 'Produk Orderan',
            'product_stock' => 'Stock Produk',
            'employees' => 'Karyawan',
            'attendance' => 'Absensi',
            'performance' => 'Peforma',
        ];

        return match ($unit) {
            self::UNIT_BALI => $sharedMenus + [
                'products' => 'Katalog Produk',
                'guests' => 'Daftar Tamu',
                'categories' => 'Kategori Produk',
                'popular_categories' => 'Kategori Terlaris',
                'materials' => 'Material',
                'expenses' => 'Pengeluaran',
            ],
            self::UNIT_FACTORY => $sharedMenus + [
                'subcontractor_products' => 'Produk Subcon',
                'subcontractors' => 'Subkontraktor',
                'materials' => 'Material',
                'expenses' => 'Pengeluaran',
            ],
            default => [],
        };
    }

    public function workspaceName(): string
    {
        if ($this->isOwner()) {
            return 'Pilar Management';
        }

        return match ($this->accessibleUnits()[0] ?? null) {
            self::UNIT_BALI => 'Pilar Bali',
            self::UNIT_FACTORY => 'Pilar Factory',
            default => 'Pilar Management',
        };
    }

    public function businessUnitLabel(): string
    {
        if ($this->isOwner()) {
            return 'Owner · Bali & Factory';
        }

        $unitLabels = collect($this->accessibleUnits())
            ->map(fn (string $unit): string => $unit === self::UNIT_FACTORY ? 'Pilar Factory' : 'Pilar Bali')
            ->implode(' & ');

        return 'Admin · '.$unitLabels;
    }
}
