<?php

namespace App\Models;

use App\Enums\MemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A bundle of permissions.
 *
 * Roles carry no authority of their own: policies and route middleware always ask for
 * a permission, never a role, so re-scoping an office is a matter of which permissions
 * sit in its bundle. See App\Enums\Permission.
 *
 * Two kinds live in this table. The constitution's offices come from App\Enums\MemberRole
 * and are seeded — they may be re-scoped but never renamed or deleted, because code
 * elsewhere assigns them by name (CommitteeRoleSync, MemberInviter). Roles the
 * administrator adds are marked `is_system = false` and are theirs to do as they like
 * with. Only App\Domain\Roles\RoleManager writes to any of it.
 *
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property Carbon|null $permissions_customised_at
 */
class Role extends SpatieRole
{
    /**
     * True unless RoleManager says otherwise, so RoleSeeder's prune still sweeps up
     * a legacy row from an older seed while leaving the administrator's roles alone.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_system' => true,
    ];

    /** Roles the administrator created, which alone may be renamed and deleted. */
    public function scopeCustom(Builder $query): void
    {
        $query->where('is_system', false);
    }

    /**
     * Human-readable name, e.g. "Vice-Treasurer" for `vice_treasurer`.
     *
     * An office keeps the wording MemberRole gives it, so the roles screen and the
     * rest of the portal never call the same person two different things.
     */
    public function label(): string
    {
        return MemberRole::tryFrom($this->name)?->label()
            ?? str($this->name)->replace(['_', '-'], ' ')->headline()->toString();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'permissions_customised_at' => 'datetime',
        ];
    }
}
