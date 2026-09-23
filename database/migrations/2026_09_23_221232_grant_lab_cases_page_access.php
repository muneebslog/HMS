<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give the new Lab Cases page to every role that can already open Lab Tests.
     */
    public function up(): void
    {
        $roles = RolePagePermission::query()
            ->where('route_name', 'lab.tests')
            ->pluck('role')
            ->map(fn ($role) => $role instanceof UserRole ? $role : UserRole::from($role))
            ->unique();

        foreach ($roles as $role) {
            $exists = RolePagePermission::query()
                ->where('role', $role)
                ->where('route_name', 'lab.cases')
                ->exists();

            if (! $exists) {
                RolePagePermission::query()->insert([
                    'role' => $role->value,
                    'route_name' => 'lab.cases',
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
        $roles = RolePagePermission::query()
            ->where('route_name', 'lab.cases')
            ->pluck('role')
            ->map(fn ($role) => $role instanceof UserRole ? $role : UserRole::from($role))
            ->unique();

        RolePagePermission::query()->where('route_name', 'lab.cases')->delete();

        foreach ($roles as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }
};
