<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Reception, management and doctors get "Medical Certificates" and its print view.
     */
    public function up(): void
    {
        $roles = [UserRole::Receptionist, UserRole::Management, UserRole::Doctor];

        foreach ($roles as $role) {
            foreach (['reception.medical-certificates', 'reception.medical-certificates.print'] as $routeName) {
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

            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()
            ->whereIn('route_name', ['reception.medical-certificates', 'reception.medical-certificates.print'])
            ->delete();

        foreach (UserRole::cases() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }
};
