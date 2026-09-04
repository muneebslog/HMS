<?php

use App\Services\RoleActingService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * The role currently being acted as, if any.
     */
    #[Computed]
    public function actingAs(): ?\App\Enums\UserRole
    {
        if (! auth()->user()?->isActuallyAdmin()) {
            return null;
        }

        return app(RoleActingService::class)->current();
    }

    /**
     * Stop acting as another role and restore the admin view.
     */
    public function stopActing(): void
    {
        abort_unless(auth()->user()?->isActuallyAdmin(), 403);

        app(RoleActingService::class)->stop();

        unset($this->actingAs);

        Flux::toast(text: __('Stopped acting as another role.'), variant: 'success');

        $this->redirect(route('admin.act-as-role'), navigate: true);
    }
}; ?>

<div>
    @if ($this->actingAs)
        <div class="border-b border-amber-300 bg-amber-50 px-4 py-2 dark:border-amber-700 dark:bg-amber-950/40" data-test="role-acting-banner">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-sm text-amber-900 dark:text-amber-100">
                    <flux:icon.eye class="size-4 shrink-0" />
                    <span>
                        {{ __('Acting as :role — pages and navigation match that role.', ['role' => $this->actingAs->label()]) }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" :href="route('admin.act-as-role')" wire:navigate>
                        {{ __('Change') }}
                    </flux:button>
                    <flux:button size="sm" variant="primary" wire:click="stopActing" data-test="stop-acting-role">
                        {{ __('Stop acting') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
