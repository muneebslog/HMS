<?php

use App\Services\PageAccessService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Stats')] class extends Component
{
    public function mount(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && app(PageAccessService::class)->canAccessAny($user, [
                'admin.monthly-report',
                'admin.procedure-finances',
                'admin.service-stats',
                'admin.medication-deliveries',
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
            'label' => __('Monthly Report'),
            'description' => __('Monthly hospital performance report.'),
            'icon' => 'chart-bar',
            'route' => 'admin.monthly-report',
            'show' => $pageAccess->canAccess($user, 'admin.monthly-report'),
        ],
        [
            'label' => __('Procedure Finances'),
            'description' => __('Procedure revenue and cost breakdown.'),
            'icon' => 'banknotes',
            'route' => 'admin.procedure-finances',
            'show' => $pageAccess->canAccess($user, 'admin.procedure-finances'),
        ],
        [
            'label' => __('Service Statistics'),
            'description' => __('Service volume and usage statistics.'),
            'icon' => 'chart-bar',
            'route' => 'admin.service-stats',
            'show' => $pageAccess->canAccess($user, 'admin.service-stats'),
        ],
        [
            'label' => __('Medication Deliveries'),
            'description' => __('Medication delivery history and counts.'),
            'icon' => 'beaker',
            'route' => 'admin.medication-deliveries',
            'show' => $pageAccess->canAccess($user, 'admin.medication-deliveries'),
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
        <flux:heading level="1">{{ __('Stats') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Reports, finances, and delivery statistics.') }}</flux:text>
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
