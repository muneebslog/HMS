<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class PageAccessService
{
    /**
     * Determine whether the user can access the given route.
     */
    public function canAccess(User $user, string $routeName): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isUser()) {
            return $routeName === 'pending-role';
        }

        if (in_array($routeName, config('pages.always_accessible', []), true)) {
            return true;
        }

        $resolvedRoute = $this->resolveRouteName($routeName);

        if (! array_key_exists($resolvedRoute, config('pages.pages', []))) {
            return false;
        }

        return in_array($resolvedRoute, $this->routeNamesForRole($user->effectiveRole()), true);
    }

    /**
     * Determine whether the user can access any route in the given group.
     *
     * @param  list<string>  $routeNames
     */
    public function canAccessAny(User $user, array $routeNames): bool
    {
        foreach ($routeNames as $routeName) {
            if ($this->canAccess($user, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get route names accessible to the user.
     *
     * @return list<string>
     */
    public function accessibleRouteNames(User $user): array
    {
        if ($user->isAdmin()) {
            return array_keys(config('pages.pages', []));
        }

        if ($user->isUser()) {
            return ['pending-role'];
        }

        return $this->routeNamesForRole($user->effectiveRole());
    }

    /**
     * Get manageable pages grouped for the admin UI.
     *
     * @return Collection<string, Collection<int, array{route: string, label: string, admin_only: bool}>>
     */
    public function manageablePagesGrouped(): Collection
    {
        $pages = config('pages.pages', []);
        $groups = config('pages.groups', []);

        $manageable = collect($pages)
            ->filter(fn (array $page) => ! isset($page['parent']))
            ->map(fn (array $page, string $route) => [
                'route' => $route,
                'label' => $page['label'],
                'group' => $page['group'],
                'admin_only' => (bool) ($page['admin_only'] ?? false),
            ]);

        return collect($groups)
            ->mapWithKeys(fn (string $group) => [
                $group => $manageable->where('group', $group)->values(),
            ])
            ->filter(fn (Collection $items) => $items->isNotEmpty());
    }

    /**
     * Get route names currently granted to a role.
     *
     * @return list<string>
     */
    public function routesForRole(UserRole $role): array
    {
        if ($role === UserRole::Admin) {
            return array_keys(config('pages.pages', []));
        }

        return $this->routeNamesForRole($role);
    }

    /**
     * Get manageable route names currently granted to a role (for admin UI checkboxes).
     *
     * @return list<string>
     */
    public function manageableRoutesForRole(UserRole $role): array
    {
        $manageableRoutes = $this->manageablePagesGrouped()
            ->flatten(1)
            ->pluck('route')
            ->all();

        return array_values(array_intersect($this->routesForRole($role), $manageableRoutes));
    }

    /**
     * Sync page permissions for a role.
     *
     * @param  list<string>  $routeNames
     */
    public function syncForRole(UserRole $role, array $routeNames): void
    {
        if ($role === UserRole::User) {
            throw new InvalidArgumentException('Cannot assign page access to the default user role.');
        }

        $pages = config('pages.pages', []);
        $manageableRoutes = $this->manageablePagesGrouped()
            ->flatten(1)
            ->pluck('route')
            ->all();

        $validRoutes = collect($routeNames)
            ->filter(fn (string $routeName) => in_array($routeName, $manageableRoutes, true))
            ->unique()
            ->values();

        if ($role !== UserRole::Admin) {
            $validRoutes = $validRoutes->reject(function (string $routeName) use ($pages): bool {
                return (bool) ($pages[$routeName]['admin_only'] ?? false);
            });
        }

        $expandedRoutes = $validRoutes
            ->flatMap(fn (string $routeName) => $this->expandRouteWithChildren($routeName))
            ->unique()
            ->values();

        RolePagePermission::query()->where('role', $role)->delete();

        $rows = $expandedRoutes
            ->map(fn (string $routeName) => [
                'role' => $role,
                'route_name' => $routeName,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            RolePagePermission::query()->insert($rows);
        }

        $this->clearCacheForRole($role);
    }

    /**
     * Reset a role to its default permissions from config.
     */
    public function resetRoleToDefaults(UserRole $role): void
    {
        (new RolePagePermissionSeeder)->resetRole($role);
    }

    /**
     * Clear cached permissions for a role.
     */
    public function clearCacheForRole(UserRole $role): void
    {
        Cache::forget($this->cacheKey($role));
    }

    /**
     * Resolve a route name to its permission parent when applicable.
     */
    public function resolveRouteName(string $routeName): string
    {
        $pages = config('pages.pages', []);

        while (isset($pages[$routeName]['parent'])) {
            $routeName = $pages[$routeName]['parent'];
        }

        return $routeName;
    }

    /**
     * Get cached route names for a role.
     *
     * @return list<string>
     */
    private function routeNamesForRole(UserRole $role): array
    {
        return Cache::rememberForever($this->cacheKey($role), function () use ($role): array {
            return RolePagePermission::query()
                ->where('role', $role)
                ->pluck('route_name')
                ->all();
        });
    }

    /**
     * Expand a route to include all child routes defined in the registry.
     *
     * @return list<string>
     */
    private function expandRouteWithChildren(string $routeName): array
    {
        $routes = [$routeName];

        foreach (config('pages.pages', []) as $childRoute => $page) {
            if (($page['parent'] ?? null) === $routeName) {
                $routes = array_merge($routes, $this->expandRouteWithChildren($childRoute));
            }
        }

        return $routes;
    }

    /**
     * Build the cache key for a role.
     */
    private function cacheKey(UserRole $role): string
    {
        return 'page_access.'.$role->value;
    }
}
