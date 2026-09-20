<?php

use App\Services\PageAccessService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin Side')] class extends Component
{
    public function mount(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && app(PageAccessService::class)->canAccessAny($user, [
                'admin.policy-journal',
                'admin.notifications',
                'admin.reports',
            ]),
            403
        );
    }
}; ?>

@php
    $pageAccess = app(\App\Services\PageAccessService::class);
    $user = auth()->user();

    $cards = [
        [
            'label' => __('Policy Journal'),
            'description' => __('Hospital policies and attachments.'),
            'icon' => 'book-open',
            'route' => 'admin.policy-journal',
            'show' => $pageAccess->canAccess($user, 'admin.policy-journal'),
        ],
        [
            'label' => __('Notifications'),
            'description' => __('Broadcast and review admin notifications.'),
            'icon' => 'bell',
            'route' => 'admin.notifications',
            'show' => $pageAccess->canAccess($user, 'admin.notifications'),
        ],
        [
            'label' => __('Reports to Admin'),
            'description' => __('Incoming reports submitted to admin.'),
            'icon' => 'inbox-arrow-down',
            'route' => 'admin.reports',
            'show' => $pageAccess->canAccess($user, 'admin.reports'),
        ],
    ];
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:button
            :href="route('extras')"
            wire:navigate
            variant="ghost"
            icon="arrow-left"
            size="sm"
            class="mb-2"
        >
            {{ __('Extras') }}
        </flux:button>
        <flux:heading level="1">{{ __('Admin Side') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Administrative tools and oversight pages.') }}</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($cards as $card)
            @if ($card['show'])
                <a href="{{ route($card['route']) }}" wire:navigate class="block">
                    <flux:card class="h-full transition hover:bg-zinc-50 dark:hover:bg-zinc-900">
                        <div class="flex items-start gap-3">
                            <div class="rounded-lg bg-zinc-100 p-2 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                <flux:icon :name="$card['icon']" class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <flux:heading size="md">{{ $card['label'] }}</flux:heading>
                                <flux:text class="mt-1">{{ $card['description'] }}</flux:text>
                            </div>
                        </div>
                    </flux:card>
                </a>
            @endif
        @endforeach
    </div>
</div>
