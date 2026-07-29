<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reports to Admin')] class extends Component
{
    /**
     * Restrict the page to admin users.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex items-center gap-3">
            <flux:heading level="1">{{ __('Reports to Admin') }}</flux:heading>
        </div>

        <flux:card>
            <livewire:report-to-admin :limit="50" />
        </flux:card>
    </div>
</div>
