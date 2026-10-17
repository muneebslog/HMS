@props(['current' => null])

@php
    $pageAccess = app(\App\Services\PageAccessService::class);
    $user = auth()->user();

    $links = collect([
        ['route' => 'hr.pairing', 'label' => __('Pairing desk'), 'icon' => 'finger-print'],
        ['route' => 'hr.backlog', 'label' => __('Backlog'), 'icon' => 'calendar-days'],
        ['route' => 'hr.leaves', 'label' => __('Leaves'), 'icon' => 'sun'],
        ['route' => 'hr.sheet', 'label' => __('Monthly sheet'), 'icon' => 'table-cells'],
        ['route' => 'admin.employees', 'label' => __('Staff'), 'icon' => 'identification'],
        ['route' => 'admin.attendance', 'label' => __('Machine'), 'icon' => 'cpu-chip'],
        ['route' => 'hr.settings', 'label' => __('Shifts & rules'), 'icon' => 'cog-6-tooth'],
    ])->filter(fn (array $link): bool => $user->isAdmin() || $pageAccess->canAccess($user, $link['route']));
@endphp

<flux:navbar class="-mb-px flex-wrap border-b border-zinc-200 dark:border-zinc-700">
    @foreach ($links as $link)
        <flux:navbar.item
            wire:key="hr-nav-{{ $link['route'] }}"
            :icon="$link['icon']"
            :href="route($link['route'])"
            :current="$current === $link['route']"
            wire:navigate
        >
            {{ $link['label'] }}
        </flux:navbar.item>
    @endforeach
</flux:navbar>
