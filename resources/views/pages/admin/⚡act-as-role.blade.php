<?php

use App\Enums\UserRole;
use App\Services\RoleActingService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Act as Role')] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->isActuallyAdmin(), 403);
    }

    /**
     * Roles available for preview.
     *
     * @return Collection<int, UserRole>
     */
    #[Computed]
    public function roles(): Collection
    {
        return collect(app(RoleActingService::class)->selectableRoles());
    }

    /**
     * The role currently being acted as, if any.
     */
    #[Computed]
    public function actingAs(): ?UserRole
    {
        return app(RoleActingService::class)->current();
    }

    /**
     * Start acting as the given role.
     */
    public function startActing(string $role): void
    {
        abort_unless(auth()->user()?->isActuallyAdmin(), 403);

        $validatedRole = UserRole::tryFrom($role);

        if ($validatedRole === null || ! in_array($validatedRole, app(RoleActingService::class)->selectableRoles(), true)) {
            abort(403);
        }

        app(RoleActingService::class)->start(auth()->user(), $validatedRole);

        Flux::toast(text: __('Now acting as :role.', ['role' => $validatedRole->label()]), variant: 'success');

        $this->redirect(route('dashboard'), navigate: true);
    }

    /**
     * Stop acting as another role.
     */
    public function stopActing(): void
    {
        abort_unless(auth()->user()?->isActuallyAdmin(), 403);

        app(RoleActingService::class)->stop();

        unset($this->actingAs);

        Flux::toast(text: __('Stopped acting as another role.'), variant: 'success');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Act as Role') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Preview the app as another role — same pages and navigation they see. You stay logged in as yourself.') }}</flux:text>
    </div>

    @if ($this->actingAs)
        <flux:callout variant="warning" icon="eye" :dismissible="false">
            <flux:callout.heading>{{ __('Currently acting as :role', ['role' => $this->actingAs->label()]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('Sidebar and page access match this role. Stop when you are done.') }}</flux:callout.text>
            <x-slot:actions>
                <flux:button variant="primary" wire:click="stopActing">{{ __('Stop acting') }}</flux:button>
            </x-slot:actions>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->roles as $role)
            <flux:card wire:key="act-as-{{ $role->value }}" class="flex flex-col gap-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="md">{{ $role->label() }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Uses that role\'s page access and navigation.') }}</flux:text>
                    </div>
                    @if ($this->actingAs === $role)
                        <flux:badge color="amber" size="sm">{{ __('Active') }}</flux:badge>
                    @endif
                </div>

                <div class="mt-auto">
                    @if ($this->actingAs === $role)
                        <flux:button variant="ghost" wire:click="stopActing" class="w-full">
                            {{ __('Stop acting') }}
                        </flux:button>
                    @else
                        <flux:button variant="primary" wire:click="startActing('{{ $role->value }}')" class="w-full" wire:loading.attr="disabled">
                            {{ __('Act as :role', ['role' => $role->label()]) }}
                        </flux:button>
                    @endif
                </div>
            </flux:card>
        @endforeach
    </div>
</div>
