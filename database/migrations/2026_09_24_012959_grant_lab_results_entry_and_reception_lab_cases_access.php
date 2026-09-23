<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * - Everyone who can open Lab Cases today keeps entering results: grant them "lab.results.entry".
     * - Reception gets Lab Cases (view, search, status, print) without results entry.
     */
    public function up(): void
    {
        $resultEntryRoles = RolePagePermission::query()
            ->where('route_name', 'lab.cases')
            ->pluck('role')
            ->map(fn ($role) => $role instanceof UserRole ? $role : UserRole::from($role))
            ->unique();

        foreach ($resultEntryRoles as $role) {
            $this->grant($role, 'lab.results.entry');
        }

        $this->grant(UserRole::Receptionist, 'lab.cases');

        foreach ($resultEntryRoles->push(UserRole::Receptionist)->unique() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()->where('route_name', 'lab.results.entry')->delete();
        RolePagePermission::query()
            ->where('role', UserRole::Receptionist)
            ->where('route_name', 'lab.cases')
            ->delete();

        foreach (UserRole::cases() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }

    /**
     * Give a role a permission if it does not have it yet.
     */
    private function grant(UserRole $role, string $routeName): void
    {
        $exists = RolePagePermission::query()
            ->where('role', $role)
            ->where('route_name', $routeName)
            ->exists();

        if (! $exists) {
            RolePagePermission::query()->insert([
                'role' => $role->value,
                'route_name' => $routeName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
