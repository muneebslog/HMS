<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\PageAccessService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Page Access')] class extends Component
{
    public bool $showModal = false;

    public ?string $modalRole = null;

    /** @var list<string> */
    public array $selectedRoutes = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Get all roles for the overview table.
     *
     * @return SupportCollection<int, array{role: UserRole, users_count: int, pages_count: int}>
     */
    #[Computed]
    public function rolesOverview(): SupportCollection
    {
        $service = app(PageAccessService::class);
        $userCounts = User::query()
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return collect(UserRole::cases())->map(function (UserRole $role) use ($service, $userCounts): array {
            $manageableCount = count($service->manageableRoutesForRole($role));

            return [
                'role' => $role,
                'users_count' => (int) ($userCounts[$role->value] ?? 0),
                'pages_count' => $role === UserRole::User ? 0 : $manageableCount,
            ];
        });
    }

    /**
     * Get users assigned to the modal role.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function modalUsers(): Collection
    {
        if ($this->modalRole === null) {
            return new Collection;
        }

        return User::query()
            ->where('role', $this->modalRole)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get manageable pages grouped for the modal editor.
     *
     * @return SupportCollection<string, SupportCollection<int, array{route: string, label: string, admin_only: bool}>>
     */
    #[Computed]
    public function groupedPages(): SupportCollection
    {
        return app(PageAccessService::class)->manageablePagesGrouped();
    }

    /**
     * Get the role currently open in the modal.
     */
    #[Computed]
    public function activeRole(): ?UserRole
    {
        if ($this->modalRole === null) {
            return null;
        }

        return UserRole::from($this->modalRole);
    }

    /**
     * Determine whether the modal role is admin.
     */
    #[Computed]
    public function isAdminRole(): bool
    {
        return $this->modalRole === UserRole::Admin->value;
    }

    /**
     * Determine whether the modal role is the unassigned user role.
     */
    #[Computed]
    public function isUserRole(): bool
    {
        return $this->modalRole === UserRole::User->value;
    }

    /**
     * Open the role detail modal.
     */
    public function openRole(string $roleValue): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->modalRole = $roleValue;
        $this->loadRolePermissions();
        $this->showModal = true;
    }

    /**
     * Close the role detail modal.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->modalRole = null;
        $this->selectedRoutes = [];
        $this->resetValidation();
    }

    /**
     * Save page access for the modal role.
     */
    public function save(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if ($this->modalRole === null) {
            return;
        }

        $this->validate([
            'modalRole' => ['required', Rule::enum(UserRole::class)],
            'selectedRoutes' => ['array'],
            'selectedRoutes.*' => ['string'],
        ]);

        $role = UserRole::from($this->modalRole);

        if ($role === UserRole::User) {
            Flux::toast(variant: 'info', text: __('Unassigned users cannot be granted page access.'));

            return;
        }

        if ($role === UserRole::Admin) {
            Flux::toast(variant: 'info', text: __('Admin always has access to all pages.'));

            return;
        }

        app(PageAccessService::class)->syncForRole($role, $this->selectedRoutes);

        Flux::toast(variant: 'success', text: __('Page access updated for :role.', ['role' => $role->label()]));

        unset($this->rolesOverview);
    }

    /**
     * Reset the modal role to default permissions.
     */
    public function resetToDefaults(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if ($this->modalRole === null) {
            return;
        }

        $role = UserRole::from($this->modalRole);

        if ($role === UserRole::User) {
            return;
        }

        app(PageAccessService::class)->resetRoleToDefaults($role);
        $this->loadRolePermissions();

        unset($this->rolesOverview);

        Flux::toast(variant: 'success', text: __('Page access reset to defaults for :role.', ['role' => $role->label()]));
    }

    /**
     * Toggle all routes in a group.
     */
    public function toggleGroup(string $group, bool $checked): void
    {
        if ($this->isAdminRole || $this->isUserRole) {
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
     * Load the selected routes for the modal role.
     */
    private function loadRolePermissions(): void
    {
        if ($this->modalRole === null) {
            $this->selectedRoutes = [];

            return;
        }

        $role = UserRole::from($this->modalRole);

        $this->selectedRoutes = app(PageAccessService::class)->manageableRoutesForRole($role);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Page Access') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Manage which pages each role can access.') }}</flux:text>
    </div>

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Users') }}</flux:table.column>
                <flux:table.column>{{ __('Pages') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->rolesOverview as $row)
                    <flux:table.row wire:key="role-{{ $row['role']->value }}">
                        <flux:table.cell>
                            <flux:text class="font-medium">{{ $row['role']->label() }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $row['users_count'] }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($row['role'] === \App\Enums\UserRole::Admin)
                                <flux:badge size="sm" color="purple">{{ __('All') }}</flux:badge>
                            @elseif ($row['role'] === \App\Enums\UserRole::User)
                                <flux:badge size="sm" color="zinc">{{ __('None') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="blue">{{ $row['pages_count'] }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button size="sm" variant="ghost" wire:click="openRole('{{ $row['role']->value }}')">
                                {{ __('Manage') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="max-w-4xl">
        @if ($this->activeRole)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $this->activeRole->label() }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Users with this role and the pages they can access.') }}</flux:text>
                </div>

                <div class="space-y-3">
                    <flux:heading size="md">{{ __('Users') }} ({{ $this->modalUsers->count() }})</flux:heading>

                    @if ($this->modalUsers->isEmpty())
                        <flux:callout variant="secondary" icon="users">
                            {{ __('No users currently have this role.') }}
                        </flux:callout>
                    @else
                        <div class="max-h-40 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                                    <flux:table.column>{{ __('Email') }}</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($this->modalUsers as $user)
                                        <flux:table.row wire:key="modal-user-{{ $user->id }}">
                                            <flux:table.cell>{{ $user->name }}</flux:table.cell>
                                            <flux:table.cell>{{ $user->email }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    @endif
                </div>

                <flux:separator />

                <div class="space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading size="md">{{ __('Page Access') }}</flux:heading>

                        @unless ($this->isAdminRole || $this->isUserRole)
                            <div class="flex gap-2">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="resetToDefaults"
                                    wire:confirm="{{ __('Reset this role to default page access?') }}"
                                >
                                    {{ __('Reset to Defaults') }}
                                </flux:button>
                                <flux:button size="sm" variant="primary" wire:click="save">
                                    {{ __('Save Changes') }}
                                </flux:button>
                            </div>
                        @endunless
                    </div>

                    @if ($this->isAdminRole)
                        <flux:callout variant="info" icon="information-circle">
                            {{ __('Admins always have full access to every page.') }}
                        </flux:callout>
                    @elseif ($this->isUserRole)
                        <flux:callout variant="secondary" icon="information-circle">
                            {{ __('Unassigned users can only access the pending role page until an admin assigns them a role.') }}
                        </flux:callout>
                    @else
                        <div class="max-h-[28rem] space-y-6 overflow-y-auto pr-1">
                            @foreach ($this->groupedPages as $group => $pages)
                                <div wire:key="modal-group-{{ $group }}" class="space-y-3">
                                    <div class="flex items-center justify-between border-b border-zinc-200 pb-2 dark:border-zinc-700">
                                        <flux:heading size="sm">{{ __($group) }}</flux:heading>
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            wire:click="toggleGroup(@js($group), true)"
                                        >
                                            {{ __('Select All') }}
                                        </flux:button>
                                    </div>

                                    <div class="grid gap-2 sm:grid-cols-2">
                                        @foreach ($pages as $page)
                                            <div wire:key="modal-page-{{ $page['route'] }}" class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                                @if ($page['admin_only'])
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
                    @endif
                </div>

                <div class="flex justify-end">
                    <flux:button variant="ghost" wire:click="closeModal">{{ __('Close') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
