<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Everyone who can see Lab Cases gets "Outsourced Tests".
     */
    public function up(): void
    {
        $roles = RolePagePermission::query()
            ->where('route_name', 'lab.cases')
            ->pluck('role')
            ->map(fn ($role) => $role instanceof UserRole ? $role : UserRole::from($role))
            ->unique();

        foreach ($roles as $role) {
            $exists = RolePagePermission::query()
                ->where('role', $role)
                ->where('route_name', 'lab.outsourced')
                ->exists();

            if (! $exists) {
                RolePagePermission::query()->insert([
                    'role' => $role->value,
                    'route_name' => 'lab.outsourced',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()->where('route_name', 'lab.outsourced')->delete();

        foreach (UserRole::cases() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }
};
