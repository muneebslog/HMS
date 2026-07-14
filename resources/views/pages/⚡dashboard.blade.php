<?php

use App\Models\AdminNotification;
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
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
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

                        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Opening Balance') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->currentShift->opening_balance, 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Walk-in Sales') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->currentShift->totalWalkInSales(), 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Lab Sales') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->currentShift->totalLabSales(), 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Procedure Payments') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->currentShift->totalProcedureSales(), 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Total Sales') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->currentShift->totalSales(), 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Expected Cash') }}</flux:text>
                                <flux:heading level="3">{{ number_format($this->currentShift->expectedCash(), 2) }}</flux:heading>
                            </div>
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

                        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Opening Balance') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->lastClosedShift->opening_balance, 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Closing Balance') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->lastClosedShift->closing_balance, 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Total Sales') }}</flux:text>
                                <flux:text class="font-semibold">{{ number_format($this->lastClosedShift->totalSales(), 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Expenses') }}</flux:text>
                                <flux:text class="font-semibold text-red-600">-{{ number_format($this->lastClosedShift->totalExpenses(), 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Daily Payouts') }}</flux:text>
                                <flux:text class="font-semibold text-red-600">-{{ number_format($this->lastClosedShift->totalDailyPayouts(), 2) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Expected Cash') }}</flux:text>
                                <flux:heading level="3">{{ number_format($this->lastClosedShift->expectedCash(), 2) }}</flux:heading>
                            </div>
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

        @if (auth()->user()->isAdmin() || auth()->user()->isManagement())
            <flux:card wire:poll.10s>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <flux:heading level="2">{{ __('Notifications') }}</flux:heading>

                        @if ($this->unreadNotificationCount > 0)
                            <flux:badge size="sm" color="red">{{ $this->unreadNotificationCount }}</flux:badge>
                        @endif
                    </div>

                    @if ($this->unreadNotificationCount > 0)
                        <flux:button size="sm" variant="ghost" wire:click="markAllNotificationsAsRead">
                            {{ __('Mark all as read') }}
                        </flux:button>
                    @endif
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
    </div>
</div>
