<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $routeNames = ['gyne.intake', 'gyne.ultrasound'];

    /**
     * Give the gyne head access to the gyne intake and ultrasound pages.
     */
    public function up(): void
    {
        foreach ($this->routeNames as $routeName) {
            $exists = RolePagePermission::query()
                ->where('role', UserRole::GyneHead)
                ->where('route_name', $routeName)
                ->exists();

            if (! $exists) {
                RolePagePermission::query()->insert([
                    'role' => UserRole::GyneHead->value,
                    'route_name' => $routeName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        app(PageAccessService::class)->clearCacheForRole(UserRole::GyneHead);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()
            ->where('role', UserRole::GyneHead)
            ->delete();

        app(PageAccessService::class)->clearCacheForRole(UserRole::GyneHead);
    }
};
