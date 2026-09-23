<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * - Reception gets "Lab Samples" (call the rider, hand samples over, retakes).
     * - Everyone who enters lab results gets "Sample Receiving".
     */
    public function up(): void
    {
        $labRoles = RolePagePermission::query()
            ->where('route_name', 'lab.results.entry')
            ->pluck('role')
            ->map(fn ($role) => $role instanceof UserRole ? $role : UserRole::from($role))
            ->unique();

        foreach ($labRoles as $role) {
            $this->grant($role, 'lab.samples');
        }

        $this->grant(UserRole::Receptionist, 'reception.lab-samples');

        foreach ($labRoles->push(UserRole::Receptionist)->unique() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()->whereIn('route_name', ['lab.samples', 'reception.lab-samples'])->delete();

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
