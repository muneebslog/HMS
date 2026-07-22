<?php

use App\Models\AdminNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component
{
    use WithPagination;

    public string $filter = 'all';

    /**
     * Restrict the page to admin and management users.
     */
    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null || (! $user->isAdmin() && ! $user->isManagement())) {
            abort(403);
        }
    }

    /**
     * Get the paginated notifications based on the current filter.
     *
     * @return LengthAwarePaginator<AdminNotification>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        $query = AdminNotification::with('user')->latest();

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'read') {
            $query->whereNotNull('read_at');
        }

        return $query->paginate(20);
    }

    /**
     * Get the count of unread notifications.
     */
    #[Computed]
    public function unreadCount(): int
    {
        return AdminNotification::whereNull('read_at')->count();
    }

    /**
     * Mark the given notification as read.
     */
    public function markAsRead(int $notificationId): void
    {
        $notification = AdminNotification::find($notificationId);

        if ($notification !== null) {
            $notification->markAsRead();
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(): void
    {
        AdminNotification::whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * Set the filter and reset pagination.
     */
    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'unread', 'read'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->resetPage();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <flux:heading level="1">{{ __('Notifications') }}</flux:heading>

                @if ($this->unreadCount > 0)
                    <flux:badge size="sm" color="red">{{ $this->unreadCount }}</flux:badge>
                @endif
            </div>

            @if ($this->unreadCount > 0)
                <flux:button size="sm" variant="ghost" icon="check" wire:click="markAllAsRead">
                    {{ __('Mark all as read') }}
                </flux:button>
            @endif
        </div>

        <div class="flex gap-2">
            <flux:button
                size="sm"
                variant="{{ $filter === 'all' ? 'primary' : 'ghost' }}"
                wire:click="setFilter('all')"
            >
                {{ __('All') }}
            </flux:button>

            <flux:button
                size="sm"
                variant="{{ $filter === 'unread' ? 'primary' : 'ghost' }}"
                wire:click="setFilter('unread')"
            >
                {{ __('Unread') }}
            </flux:button>

            <flux:button
                size="sm"
                variant="{{ $filter === 'read' ? 'primary' : 'ghost' }}"
                wire:click="setFilter('read')"
            >
                {{ __('Read') }}
            </flux:button>
        </div>

        <div class="space-y-3">
            @forelse ($this->notifications as $notification)
                <div
                    wire:key="notification-{{ $notification->id }}"
                    class="flex items-start gap-4 rounded-lg border p-4 {{ $notification->isRead() ? 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50' : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' }}"
                >
                    <div class="mt-1 shrink-0">
                        @if ($notification->isRead())
                            <flux:icon name="bell" class="size-5 text-zinc-400" />
                        @else
                            <flux:icon name="bell-alert" class="size-5 text-amber-500" />
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading level="3" class="text-base {{ $notification->isRead() ? 'text-zinc-600 dark:text-zinc-400' : '' }}">
                                {{ $notification->title }}
                            </flux:heading>

                            @if (! $notification->isRead())
                                <flux:badge size="sm" color="red">{{ __('New') }}</flux:badge>
                            @endif
                        </div>

                        <flux:text class="mt-1 text-zinc-500">{{ $notification->message }}</flux:text>

                        <flux:text class="mt-2 text-xs text-zinc-400">
                            {{ $notification->created_at->diffForHumans() }}
                            @if ($notification->user)
                                &middot; {{ $notification->user->name }}
                            @endif
                            @if ($notification->isRead())
                                &middot; {{ __('Read') }} {{ $notification->read_at?->diffForHumans() }}
                            @endif
                        </flux:text>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2">
                        @if ($notification->actionable_url)
                            <flux:button size="sm" variant="ghost" icon="eye" href="{{ $notification->actionable_url }}" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                        @endif

                        @if (! $notification->isRead())
                            <flux:button size="sm" variant="ghost" icon="check" wire:click="markAsRead({{ $notification->id }})">
                                {{ __('Mark read') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
                    <flux:text class="text-zinc-500">{{ __('No notifications found.') }}</flux:text>
                </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $this->notifications->links() }}
        </div>
    </div>
</div>
