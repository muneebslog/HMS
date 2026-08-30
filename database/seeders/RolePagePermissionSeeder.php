<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Seeder;

class RolePagePermissionSeeder extends Seeder
{
    /**
     * Seed role page permissions from config defaults.
     */
    public function run(): void
    {
        $pages = array_keys(config('pages.pages', []));
        $defaults = config('pages.defaults', []);

        foreach ($defaults as $roleValue => $routes) {
            $role = UserRole::from($roleValue);

            if ($routes === 'all') {
                $routes = $pages;
            }

            RolePagePermission::query()->where('role', $role)->delete();

            $rows = collect($routes)
                ->filter(fn (string $routeName) => in_array($routeName, $pages, true))
                ->unique()
                ->map(fn (string $routeName) => [
                    'role' => $role,
                    'route_name' => $routeName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            if ($rows !== []) {
                RolePagePermission::query()->insert($rows);
            }

            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }

    /**
     * Reset a single role to its default permissions.
     */
    public function resetRole(UserRole $role): void
    {
        $defaults = config('pages.defaults', []);
        $roleValue = $role->value;
        $pages = array_keys(config('pages.pages', []));

        if (! array_key_exists($roleValue, $defaults)) {
            return;
        }

        $routes = $defaults[$roleValue];

        if ($routes === 'all') {
            $routes = $pages;
        }

        RolePagePermission::query()->where('role', $role)->delete();

        $rows = collect($routes)
            ->filter(fn (string $routeName) => in_array($routeName, $pages, true))
            ->unique()
            ->map(fn (string $routeName) => [
                'role' => $role,
                'route_name' => $routeName,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            RolePagePermission::query()->insert($rows);
        }

        app(PageAccessService::class)->clearCacheForRole($role);
    }
}
