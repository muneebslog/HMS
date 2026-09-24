<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give the new CEO role its pages: the Lab Overview it lands on, plus read-only lab pages.
     */
    public function up(): void
    {
        foreach (['ceo.lab', 'lab.dashboard', 'lab.cases', 'reception.mr-lookup'] as $routeName) {
            $exists = RolePagePermission::query()
                ->where('role', UserRole::Ceo)
                ->where('route_name', $routeName)
                ->exists();

            if (! $exists) {
                RolePagePermission::query()->insert([
                    'role' => UserRole::Ceo->value,
                    'route_name' => $routeName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        app(PageAccessService::class)->clearCacheForRole(UserRole::Ceo);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()->where('role', UserRole::Ceo)->delete();

        app(PageAccessService::class)->clearCacheForRole(UserRole::Ceo);
    }
};
