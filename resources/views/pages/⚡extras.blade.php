<?php

use App\Services\PageAccessService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Extras')] class extends Component
{
    public function mount(): void
    {
        $user = auth()->user();
        $pageAccess = app(PageAccessService::class);

        abort_unless(
            $user !== null && (
                $user->isAdmin()
                || $pageAccess->canAccessAny($user, [
                    'management.crud',
                    'admin.users',
                    'admin.employees',
                    'admin.health-aides',
                    'admin.policy-journal',
                    'admin.notifications',
                    'admin.reports',
                    'admin.sms-logs',
                    'admin.merge-duplicates',
                    'admin.sql-runner',
                    'admin.kanban',
                    'admin.monthly-report',
                    'admin.procedure-finances',
                    'admin.service-stats',
                    'admin.medication-deliveries',
                    'reception.queue',
                    'lab-api-and-info',
                ])
            ),
            403
        );
    }
}; ?>

@php
    $pageAccess = app(\App\Services\PageAccessService::class);
    $user = auth()->user();

    $adminSideRoutes = ['admin.policy-journal', 'admin.notifications', 'admin.reports'];
    $devSideRoutes = ['admin.sms-logs', 'admin.merge-duplicates', 'admin.sql-runner', 'admin.kanban'];
    $statsRoutes = ['admin.monthly-report', 'admin.procedure-finances', 'admin.service-stats', 'admin.medication-deliveries'];

    $cards = [
        [
            'label' => __('Lab API and Info'),
            'description' => __('Lab invoices, API sync status, and retries.'),
            'icon' => 'beaker',
            'route' => 'lab-api-and-info',
            'show' => $pageAccess->canAccess($user, 'lab-api-and-info'),
        ],
        [
            'label' => __('Management CRUD'),
            'description' => __('Manage catalog data and reference tables.'),
            'icon' => 'cog-6-tooth',
            'route' => 'management.crud',
            'show' => $pageAccess->canAccess($user, 'management.crud'),
        ],
        [
            'label' => __('Users'),
            'description' => __('Manage accounts and role assignments.'),
            'icon' => 'users',
            'route' => 'admin.users',
            'show' => $pageAccess->canAccess($user, 'admin.users'),
        ],
        [
            'label' => __('Staff Profiles'),
            'description' => __('Employee profiles, documents, and photos.'),
            'icon' => 'identification',
            'route' => 'admin.employees',
            'show' => $pageAccess->canAccess($user, 'admin.employees'),
        ],
        [
            'label' => __('Health Aides'),
            'description' => __('Manage health aide accounts and access.'),
            'icon' => 'finger-print',
            'route' => 'admin.health-aides',
            'show' => $pageAccess->canAccess($user, 'admin.health-aides'),
        ],
        [
            'label' => __('Admin Side'),
            'description' => __('Policy journal, notifications, and admin reports.'),
            'icon' => 'shield-check',
            'route' => 'extras.admin-side',
            'show' => $pageAccess->canAccessAny($user, $adminSideRoutes),
        ],
        [
            'label' => __('Dev Side'),
            'description' => __('Developer tools and maintenance utilities.'),
            'icon' => 'code-bracket',
            'route' => 'extras.dev-side',
            'show' => $pageAccess->canAccessAny($user, $devSideRoutes),
        ],
        [
            'label' => __('Stats'),
            'description' => __('Reports, finances, and delivery statistics.'),
            'icon' => 'chart-bar',
            'route' => 'extras.stats',
            'show' => $pageAccess->canAccessAny($user, $statsRoutes),
        ],
        [
            'label' => __('Page Access'),
            'description' => __('Control which roles can open each page.'),
            'icon' => 'key',
            'route' => 'admin.page-access',
            'show' => $user->isAdmin(),
        ],
        [
            'label' => __('Act as Role'),
            'description' => __('Preview the app as another role.'),
            'icon' => 'eye',
            'route' => 'admin.act-as-role',
            'show' => $user->isActuallyAdmin(),
        ],
        [
            'label' => __('Queue'),
            'description' => __('Manage the reception queue and TV display.'),
            'icon' => 'queue-list',
            'route' => 'reception.queue',
            'show' => $pageAccess->canAccess($user, 'reception.queue'),
        ],
    ];
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Extras') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Admin tools and less-used pages, collected in one place.') }}</flux:text>
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
