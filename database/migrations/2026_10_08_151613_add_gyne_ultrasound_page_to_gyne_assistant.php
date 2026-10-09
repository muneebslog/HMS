<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Let the gyne assistant enter ultrasound reports on Gyne Ultrasound.
     */
    public function up(): void
    {
        $exists = RolePagePermission::query()
            ->where('role', UserRole::GyneAssistant)
            ->where('route_name', 'gyne.ultrasound')
            ->exists();

        if (! $exists) {
            RolePagePermission::query()->insert([
                'role' => UserRole::GyneAssistant->value,
                'route_name' => 'gyne.ultrasound',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PageAccessService::class)->clearCacheForRole(UserRole::GyneAssistant);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()
            ->where('role', UserRole::GyneAssistant)
            ->where('route_name', 'gyne.ultrasound')
            ->delete();

        app(PageAccessService::class)->clearCacheForRole(UserRole::GyneAssistant);
    }
};
