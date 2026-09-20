<?php

use App\Services\PageAccessService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dev Side')] class extends Component
{
    public function mount(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && app(PageAccessService::class)->canAccessAny($user, [
                'admin.sms-logs',
                'admin.merge-duplicates',
                'admin.sql-runner',
                'admin.kanban',
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
            'label' => __('SMS Logs'),
            'description' => __('Review outbound SMS messages.'),
            'icon' => 'chat-bubble-left-right',
            'route' => 'admin.sms-logs',
            'show' => $pageAccess->canAccess($user, 'admin.sms-logs'),
        ],
        [
            'label' => __('Merge Duplicates'),
            'description' => __('Merge duplicate patient records.'),
            'icon' => 'user-group',
            'route' => 'admin.merge-duplicates',
            'show' => $pageAccess->canAccess($user, 'admin.merge-duplicates'),
        ],
        [
            'label' => __('SQL Runner'),
            'description' => __('Run read queries against the database.'),
            'icon' => 'command-line',
            'route' => 'admin.sql-runner',
            'show' => $pageAccess->canAccess($user, 'admin.sql-runner'),
        ],
        [
            'label' => __('Kanban'),
            'description' => __('Track development and ops tasks.'),
            'icon' => 'squares-2x2',
            'route' => 'admin.kanban',
            'show' => $pageAccess->canAccess($user, 'admin.kanban'),
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
        <flux:heading level="1">{{ __('Dev Side') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Developer tools and maintenance utilities.') }}</flux:text>
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
