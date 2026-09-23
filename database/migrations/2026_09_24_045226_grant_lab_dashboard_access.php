<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Everyone who enters lab results gets the Lab Dashboard (lab technicians land on it after login).
     */
    public function up(): void
    {
        $labRoles = RolePagePermission::query()
            ->where('route_name', 'lab.results.entry')
            ->pluck('role')
            ->map(fn ($role) => $role instanceof UserRole ? $role : UserRole::from($role))
            ->push(UserRole::LabTechnician)
            ->unique();

        foreach ($labRoles as $role) {
            $exists = RolePagePermission::query()
                ->where('role', $role)
                ->where('route_name', 'lab.dashboard')
                ->exists();

            if (! $exists) {
                RolePagePermission::query()->insert([
                    'role' => $role->value,
                    'route_name' => 'lab.dashboard',
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
        RolePagePermission::query()->where('route_name', 'lab.dashboard')->delete();

        foreach (UserRole::cases() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }
};
