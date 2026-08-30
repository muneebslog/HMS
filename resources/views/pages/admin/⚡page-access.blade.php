<?php

use App\Enums\UserRole;
use App\Services\PageAccessService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Page Access')] class extends Component
{
    public string $selectedRole = UserRole::Receptionist->value;

    /** @var list<string> */
    public array $selectedRoutes = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->loadRolePermissions();
    }

    /**
     * Get assignable roles for the selector.
     *
     * @return Collection<int, UserRole>
     */
    #[Computed]
    public function assignableRoles(): Collection
    {
        return collect(UserRole::cases())
            ->reject(fn (UserRole $role) => $role === UserRole::User);
    }

    /**
     * Get manageable pages grouped for display.
     *
     * @return Collection<string, Collection<int, array{route: string, label: string, admin_only: bool}>>
     */
    #[Computed]
    public function groupedPages(): Collection
    {
        return app(PageAccessService::class)->manageablePagesGrouped();
    }

    /**
     * Determine whether the selected role is admin.
     */
    #[Computed]
    public function isAdminRole(): bool
    {
        return $this->selectedRole === UserRole::Admin->value;
    }

    /**
     * Load permissions when the selected role changes.
     */
    public function updatedSelectedRole(): void
    {
        $this->loadRolePermissions();
    }

    /**
     * Save page access for the selected role.
     */
    public function save(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->validate([
            'selectedRole' => ['required', Rule::enum(UserRole::class)],
            'selectedRoutes' => ['array'],
            'selectedRoutes.*' => ['string'],
        ]);

        $role = UserRole::from($this->selectedRole);

        if ($role === UserRole::User) {
            return;
        }

        if ($role === UserRole::Admin) {
            Flux::toast(variant: 'info', text: __('Admin always has access to all pages.'));

            return;
        }

        app(PageAccessService::class)->syncForRole($role, $this->selectedRoutes);

        Flux::toast(variant: 'success', text: __('Page access updated for :role.', ['role' => $role->label()]));
    }

    /**
     * Reset the selected role to default permissions.
     */
    public function resetToDefaults(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $role = UserRole::from($this->selectedRole);

        if ($role === UserRole::User) {
            return;
        }

        app(PageAccessService::class)->resetRoleToDefaults($role);
        $this->loadRolePermissions();

        Flux::toast(variant: 'success', text: __('Page access reset to defaults for :role.', ['role' => $role->label()]));
    }

    /**
     * Toggle all routes in a group.
     */
    public function toggleGroup(string $group, bool $checked): void
    {
        if ($this->isAdminRole) {
            return;
        }

        $groupRoutes = $this->groupedPages
            ->get($group, collect())
            ->reject(fn (array $page) => $page['admin_only'])
            ->pluck('route')
            ->all();

        if ($checked) {
            $this->selectedRoutes = array_values(array_unique([...$this->selectedRoutes, ...$groupRoutes]));
        } else {
            $this->selectedRoutes = array_values(array_diff($this->selectedRoutes, $groupRoutes));
        }
    }

    /**
     * Load the selected routes for the current role.
     */
    private function loadRolePermissions(): void
    {
        $role = UserRole::from($this->selectedRole);

        $this->selectedRoutes = app(PageAccessService::class)->manageableRoutesForRole($role);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading level="1">{{ __('Page Access') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Assign which pages each role can access.') }}</flux:text>
        </div>
    </div>

    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <flux:field class="sm:max-w-xs">
                <flux:label>{{ __('Role') }}</flux:label>
                <flux:select wire:model.live="selectedRole">
                    @foreach ($this->assignableRoles as $role)
                        <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="resetToDefaults" wire:confirm="{{ __('Reset this role to default page access?') }}">
                    {{ __('Reset to Defaults') }}
                </flux:button>
                <flux:button variant="primary" wire:click="save" :disabled="$this->isAdminRole">
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </div>

        @if ($this->isAdminRole)
            <flux:callout variant="info" icon="information-circle">
                {{ __('Admins always have full access to every page. Select another role to manage permissions.') }}
            </flux:callout>
        @endif

        <div class="space-y-8">
            @foreach ($this->groupedPages as $group => $pages)
                <div wire:key="group-{{ $group }}" class="space-y-3">
                    <div class="flex items-center justify-between border-b border-zinc-200 pb-2 dark:border-zinc-700">
                        <flux:heading size="lg">{{ __($group) }}</flux:heading>

                        @unless ($this->isAdminRole)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="toggleGroup(@js($group), true)"
                            >
                                {{ __('Select All') }}
                            </flux:button>
                        @endunless
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($pages as $page)
                            <div wire:key="page-{{ $page['route'] }}" class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                @if ($this->isAdminRole)
                                    <flux:checkbox checked disabled />
                                @elseif ($page['admin_only'])
                                    <flux:checkbox disabled />
                                @else
                                    <flux:checkbox
                                        wire:model="selectedRoutes"
                                        value="{{ $page['route'] }}"
                                    />
                                @endif

                                <div>
                                    <flux:text class="font-medium">{{ __($page['label']) }}</flux:text>
                                    @if ($page['admin_only'])
                                        <flux:text class="text-xs text-purple-600 dark:text-purple-400">{{ __('Admin only') }}</flux:text>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </flux:card>
</div>
