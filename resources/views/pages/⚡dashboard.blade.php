<?php

use App\Models\AdminNotification;
use App\Models\EmployeeTodo;
use App\Models\Procedure;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * Redirect doctors to their dedicated portal.
     */
    public function mount(): void
    {
        if (auth()->user()?->isDoctor()) {
            $this->redirect(route('doctor.portal'), navigate: true);
        }

        if (auth()->user()?->isIndoor()) {
            $this->redirect(route('indoor.ward'), navigate: true);
        }

        if (auth()->user()?->isInchargeNurse()) {
            $this->redirect(route('incharge.questionnaires'), navigate: true);
        }
    }

    /**
     * Get the currently open shift.
     */
    #[Computed]
    public function currentShift(): ?Shift
    {
        return Shift::current();
    }

    /**
     * Get the most recently closed shift.
     */
    #[Computed]
    public function lastClosedShift(): ?Shift
    {
        return Shift::with('user')
            ->where('status', 'closed')
            ->latest('closed_at')
            ->first();
    }

    /**
     * Get the unread admin notifications for the current user.
     *
     * @return Collection<int, AdminNotification>
     */
    #[Computed]
    public function unreadNotifications(): Collection
    {
        return AdminNotification::whereNull('read_at')
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * Get the count of unread admin notifications.
     */
    #[Computed]
    public function unreadNotificationCount(): int
    {
        return AdminNotification::whereNull('read_at')->count();
    }

    /**
     * Mark the given notification as read.
     */
    public function markNotificationAsRead(int $notificationId): void
    {
        $notification = AdminNotification::find($notificationId);

        if ($notification !== null) {
            $notification->markAsRead();
        }
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllNotificationsAsRead(): void
    {
        AdminNotification::whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * Get pending employee todos ordered by due date.
     *
     * @return Collection<int, EmployeeTodo>
     */
    #[Computed]
    public function pendingEmployeeTodos(): Collection
    {
        return EmployeeTodo::with('employee')
            ->pending()
            ->orderBy('due_date')
            ->limit(10)
            ->get();
    }

    /**
     * Get the count of pending employee todos.
     */
    #[Computed]
    public function pendingEmployeeTodoCount(): int
    {
        return EmployeeTodo::pending()->count();
    }

    /**
     * Count admitted procedures missing last-hour vitals or fetal heart readings.
     */
    #[Computed]
    public function overdueProcedureReadingCount(): int
    {
        return Procedure::query()
            ->onWard()
            ->with('procedureType')
            ->get()
            ->filter(fn (Procedure $procedure) => $procedure->isVitalsOverdue() || $procedure->isFetalHeartOverdue())
            ->count();
    }

    /**
     * Mark the given employee todo as done.
     */
    public function markEmployeeTodoDone(int $todoId): void
    {
        $todo = EmployeeTodo::find($todoId);

        if ($todo !== null && auth()->user()?->isAdmin()) {
            $todo->markAsDone(auth()->user());
        }
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        @if (auth()->user()->isAdmin())
            @php
                $adminUser = auth()->user();
                $adminFirstName = str($adminUser->name)->before(' ')->toString() ?: $adminUser->name;
                $adminGreeting = match (true) {
                    now()->hour < 12 => __('Good morning'),
                    now()->hour < 17 => __('Good afternoon'),
                    default => __('Good evening'),
                };

                $adminHubCards = [
                    [
                        'label' => __('HQ'),
                        'description' => __('Operations overview and alerts'),
                        'icon' => 'building-office-2',
                        'href' => route('hq'),
                        'icon_bg' => 'bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400',
                    ],
                    [
                        'label' => __('Reception'),
                        'description' => __('Front desk and patient intake'),
                        'icon' => 'user-plus',
                        'href' => route('reception.hub'),
                        'icon_bg' => 'bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400',
                    ],
                    [
                        'label' => __('Gyne'),
                        'description' => __('Gynecology department tools'),
                        'icon' => 'heart',
                        'href' => null,
                        'icon_bg' => 'bg-rose-100 text-rose-600 dark:bg-rose-900/30 dark:text-rose-400',
                    ],
                    [
                        'label' => __('Lab'),
                        'description' => __('Laboratory workflows'),
                        'icon' => 'beaker',
                        'href' => null,
                        'icon_bg' => 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/30 dark:text-cyan-400',
                    ],
                    [
                        'label' => __('Finance'),
                        'description' => __('Billing and financial controls'),
                        'icon' => 'banknotes',
                        'href' => null,
                        'icon_bg' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
                    ],
                    [
                        'label' => __('GPD n Er'),
                        'description' => __('General and emergency care'),
                        'icon' => 'shield-check',
                        'href' => null,
                        'icon_bg' => 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400',
                    ],
                    [
                        'label' => __('Stock n Maintainance'),
                        'description' => __('Inventory and facility upkeep'),
                        'icon' => 'wrench-screwdriver',
                        'href' => null,
                        'icon_bg' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
                    ],
                    [
                        'label' => __('HR'),
                        'description' => __('Staff and people management'),
                        'icon' => 'identification',
                        'href' => null,
                        'icon_bg' => 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
                    ],
                ];
            @endphp

            <div class="flex flex-col gap-6">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <flux:heading level="1">{{ $adminGreeting }}, {{ $adminFirstName }}</flux:heading>
                        <flux:text class="mt-1 text-zinc-500">
                            {{ __('Welcome back. Choose a department to continue.') }}
                        </flux:text>
                    </div>

                    <flux:text class="text-sm text-zinc-500">
                        {{ now()->format('l, F j, Y') }}
                    </flux:text>
                </div>

                <div class="flex flex-col gap-3">
                    <flux:heading level="2" class="text-sm font-medium uppercase tracking-wide text-zinc-500">
                        {{ __('Departments') }}
                    </flux:heading>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($adminHubCards as $card)
                            @if ($card['href'])
                                <a href="{{ $card['href'] }}" wire:navigate wire:key="admin-hub-{{ $loop->index }}" class="block">
                                    <flux:card class="group flex h-full min-h-28 items-center gap-4 transition duration-150 hover:-translate-y-0.5 hover:shadow-md">
                                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $card['icon_bg'] }} transition duration-150 group-hover:scale-105">
                                            <flux:icon :name="$card['icon']" class="size-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <flux:heading level="3" class="text-base">{{ $card['label'] }}</flux:heading>
                                            <flux:text class="mt-0.5 text-sm text-zinc-500">{{ $card['description'] }}</flux:text>
                                        </div>
                                        <flux:icon name="chevron-right" class="size-4 shrink-0 text-zinc-400 transition duration-150 group-hover:translate-x-0.5" />
                                    </flux:card>
                                </a>
                            @else
                                <flux:card wire:key="admin-hub-{{ $loop->index }}" class="group flex h-full min-h-28 items-center gap-4 transition duration-150 hover:-translate-y-0.5 hover:shadow-md">
                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $card['icon_bg'] }} transition duration-150 group-hover:scale-105">
                                        <flux:icon :name="$card['icon']" class="size-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <flux:heading level="3" class="text-base">{{ $card['label'] }}</flux:heading>
                                        <flux:text class="mt-0.5 text-sm text-zinc-500">{{ $card['description'] }}</flux:text>
                                    </div>
                                </flux:card>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        @else
            @if (auth()->user()->isAdmin() || auth()->user()->isManagement() || auth()->user()->isReceptionist())
                @if ($this->overdueProcedureReadingCount > 0)
                    <flux:callout variant="danger" icon="clock">
                        <flux:callout.heading>{{ __('Overdue ward readings') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __(':count admitted procedure(s) are missing hourly vitals or fetal heart readings.', ['count' => $this->overdueProcedureReadingCount]) }}
                        </flux:callout.text>
                        <x-slot:actions>
                            <flux:button size="sm" variant="primary" :href="route('indoor.ward')" wire:navigate>
                                {{ __('Open Indoor Ward') }}
                            </flux:button>
                        </x-slot:actions>
                    </flux:callout>
                @endif
            @endif

            @if (auth()->user()->isManagement())
            <div class="grid auto-rows-min gap-4 md:grid-cols-2" wire:poll.5s>
                <flux:card>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="2">{{ __('Current Shift') }}</flux:heading>

                        @if ($this->currentShift)
                            <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
                        @endif
                    </div>

                    @if ($this->currentShift)
                        <flux:text class="mt-2 text-zinc-500">
                            {{ __('Opened at') }} {{ $this->currentShift->opened_at->format('Y-m-d H:i') }}
                            &middot; {{ $this->currentShift->user->name }}
                        </flux:text>

                        <div class="mt-6 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50 sm:p-5">
                            <x-shift-cash-breakdown :shift="$this->currentShift" />
                        </div>

                        <div class="mt-6 flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="arrow-right" href="{{ route('reception.shift') }}">
                                {{ __('Open Shift') }}
                            </flux:button>
                        </div>
                    @else
                        <flux:text class="mt-6 text-zinc-500">
                            {{ __('There is no open shift at the moment.') }}
                        </flux:text>

                        <div class="mt-6 flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="arrow-right" href="{{ route('reception.shift') }}">
                                {{ __('Manage Shifts') }}
                            </flux:button>
                        </div>
                    @endif
                </flux:card>

                <flux:card>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="2">{{ __('Last Closed Shift') }}</flux:heading>

                        @if ($this->lastClosedShift)
                            <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
                        @endif
                    </div>

                    @if ($this->lastClosedShift)
                        <flux:text class="mt-2 text-zinc-500">
                            {{ __('Closed at') }} {{ $this->lastClosedShift->closed_at->format('Y-m-d H:i') }}
                            &middot; {{ $this->lastClosedShift->user->name }}
                        </flux:text>

                        <div class="mt-6 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50 sm:p-5">
                            <x-shift-cash-breakdown :shift="$this->lastClosedShift" :show-closing-balance="true" />
                        </div>

                        <div class="mt-6 flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="arrow-right" href="{{ route('management.shift-history') }}">
                                {{ __('Shift History') }}
                            </flux:button>
                        </div>
                    @else
                        <flux:text class="mt-6 text-zinc-500">
                            {{ __('No closed shifts found yet.') }}
                        </flux:text>

                        <div class="mt-6 flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="arrow-right" href="{{ route('management.shift-history') }}">
                                {{ __('View History') }}
                            </flux:button>
                        </div>
                    @endif
                </flux:card>
            </div>
        @else
            @if (auth()->user()->isAdmin() || auth()->user()->isReceptionist())
                <flux:card>
                    <livewire:reception-memo-board />
                </flux:card>

                <flux:card>
                    <livewire:report-to-admin :limit="5" />
                </flux:card>
            @else
                <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                        <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                        <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                        <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
            @endif
            @endif

            @if (auth()->user()->isAdmin() || auth()->user()->isManagement())
            <flux:card wire:poll.10s>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <flux:heading level="2">{{ __('Staff Todos') }}</flux:heading>

                        @if ($this->pendingEmployeeTodoCount > 0)
                            <flux:badge size="sm" color="red">{{ $this->pendingEmployeeTodoCount }}</flux:badge>
                        @endif
                    </div>

                    <flux:button size="sm" variant="ghost" :href="route('admin.employees')" wire:navigate>
                        {{ __('View all staff') }}
                    </flux:button>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($this->pendingEmployeeTodos as $todo)
                        <div wire:key="employee-todo-{{ $todo->id }}" class="flex items-start gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="mt-1 shrink-0">
                                <flux:icon name="clipboard-document-list" class="size-5 {{ $todo->isOverdue() ? 'text-red-500' : 'text-amber-500' }}" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:heading level="3" class="text-base">{{ $todo->title }}</flux:heading>
                                    @if ($todo->isOverdue())
                                        <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                                    @endif
                                </div>

                                <flux:text class="mt-1 text-sm text-zinc-500">
                                    {{ $todo->employee->name }}
                                    &middot; {{ __('Due') }} {{ $todo->due_date->format('Y-m-d') }}
                                </flux:text>
                            </div>

                            <div class="flex shrink-0 flex-col gap-2">
                                <flux:button size="sm" variant="primary" icon="check" wire:click="markEmployeeTodoDone({{ $todo->id }})">
                                    {{ __('Done') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" icon="eye" :href="route('admin.employees.profile', $todo->employee)" wire:navigate>
                                    {{ __('View') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
                            <flux:text class="text-zinc-500">{{ __('No pending staff todos.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </flux:card>
            @endif

            @if (auth()->user()->isAdmin() || auth()->user()->isManagement())
            <flux:card wire:poll.10s>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <flux:heading level="2">{{ __('Notifications') }}</flux:heading>

                        @if ($this->unreadNotificationCount > 0)
                            <flux:badge size="sm" color="red">{{ $this->unreadNotificationCount }}</flux:badge>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" :href="route('admin.notifications')" wire:navigate>
                            {{ __('View all') }}
                        </flux:button>

                        @if ($this->unreadNotificationCount > 0)
                            <flux:button size="sm" variant="ghost" wire:click="markAllNotificationsAsRead">
                                {{ __('Mark all as read') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($this->unreadNotifications as $notification)
                        <div wire:key="notification-{{ $notification->id }}" class="flex items-start gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="mt-1 shrink-0">
                                <flux:icon name="bell-alert" class="size-5 text-amber-500" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <flux:heading level="3" class="text-base">{{ $notification->title }}</flux:heading>
                                <flux:text class="mt-1 text-zinc-500">{{ $notification->message }}</flux:text>
                                <flux:text class="mt-2 text-xs text-zinc-400">
                                    {{ $notification->created_at->diffForHumans() }}
                                    @if ($notification->user)
                                        &middot; {{ $notification->user->name }}
                                    @endif
                                </flux:text>
                            </div>

                            <div class="flex shrink-0 flex-col gap-2">
                                @if ($notification->actionable_url)
                                    <flux:button size="sm" variant="ghost" icon="eye" href="{{ $notification->actionable_url }}" wire:navigate>
                                        {{ __('View') }}
                                    </flux:button>
                                @endif

                                <flux:button size="sm" variant="ghost" icon="check" wire:click="markNotificationAsRead({{ $notification->id }})">
                                    {{ __('Mark read') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
                            <flux:text class="text-zinc-500">{{ __('No new notifications.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </flux:card>
            @endif

            <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
        @endif
    </div>
</div>
