<?php

use App\Enums\KanbanStatus;
use App\Models\KanbanItem;
use App\Models\KanbanItemComment;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Kanban Board')] class extends Component
{
    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string|max:2000')]
    public ?string $description = null;

    public ?int $editingItemId = null;

    public bool $showModal = false;

    public ?int $deletingItemId = null;

    public ?int $viewingItemId = null;

    public ?string $newComment = null;

    /**
     * Restrict the page to admin users.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }

    /**
     * Get the kanban items grouped by status column.
     *
     * @return array<string, Collection<int, KanbanItem>>
     */
    #[Computed]
    public function columns(): array
    {
        $items = KanbanItem::with('creator')->get();
        $columns = [];

        foreach (KanbanStatus::ordered() as $status) {
            $columns[$status->value] = $items
                ->where('status', $status)
                ->sortBy('position')
                ->values();
        }

        return $columns;
    }

    /**
     * Get the kanban item currently being viewed with its comments.
     */
    #[Computed]
    public function viewedItem(): ?KanbanItem
    {
        if ($this->viewingItemId === null) {
            return null;
        }

        return KanbanItem::with('comments.user', 'creator')->find($this->viewingItemId);
    }

    /**
     * Open the modal to create a new item.
     */
    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    /**
     * Open the modal to edit an existing item.
     */
    public function editItem(int $id): void
    {
        $item = KanbanItem::findOrFail($id);

        $this->editingItemId = $item->id;
        $this->title = $item->title;
        $this->description = $item->description;
        $this->showModal = true;
    }

    /**
     * Save a new or edited kanban item.
     */
    public function saveItem(): void
    {
        $this->validate();

        if ($this->editingItemId === null) {
            $maxPosition = KanbanItem::where('status', KanbanStatus::Todo)->max('position') ?? -1;

            $item = KanbanItem::create([
                'title' => $this->title,
                'description' => $this->description,
                'status' => KanbanStatus::Todo,
                'position' => $maxPosition + 1,
                'created_by' => auth()->id(),
            ]);

            app(NotificationService::class)->notifyKanbanItemCreated($item, auth()->user());

            $this->dispatch('item-created');
            $this->dispatch('flux-toast', message: __('Task added successfully 🎉'));
        } else {
            $item = KanbanItem::findOrFail($this->editingItemId);
            $item->update([
                'title' => $this->title,
                'description' => $this->description,
            ]);

            app(NotificationService::class)->notifyKanbanItemUpdated($item, auth()->user());

            $this->dispatch('item-updated');
            $this->dispatch('flux-toast', message: __('Task updated successfully ✨'));
        }

        $this->closeModal();
    }

    /**
     * Move an item to a different status column.
     */
    public function moveToColumn(int $itemId, string $statusValue): void
    {
        $status = KanbanStatus::tryFrom($statusValue);

        if ($status === null) {
            return;
        }

        $item = KanbanItem::findOrFail($itemId);
        $maxPosition = KanbanItem::where('status', $status)->max('position') ?? -1;

        $item->update([
            'status' => $status,
            'position' => $maxPosition + 1,
        ]);

        app(NotificationService::class)->notifyKanbanItemMoved($item, auth()->user());

        $this->dispatch('item-moved');
        $this->dispatch('flux-toast', message: __('Task moved to :column 🚀', ['column' => $status->label()]));
    }

    /**
     * Move an item up within its current column.
     */
    public function moveUp(int $itemId): void
    {
        $item = KanbanItem::findOrFail($itemId);
        $previous = KanbanItem::where('status', $item->status)
            ->where('position', '<', $item->position)
            ->orderByDesc('position')
            ->first();

        if ($previous === null) {
            return;
        }

        $temp = $previous->position;
        $previous->update(['position' => $item->position]);
        $item->update(['position' => $temp]);

        $this->dispatch('item-reordered');
    }

    /**
     * Move an item down within its current column.
     */
    public function moveDown(int $itemId): void
    {
        $item = KanbanItem::findOrFail($itemId);
        $next = KanbanItem::where('status', $item->status)
            ->where('position', '>', $item->position)
            ->orderBy('position')
            ->first();

        if ($next === null) {
            return;
        }

        $temp = $next->position;
        $next->update(['position' => $item->position]);
        $item->update(['position' => $temp]);

        $this->dispatch('item-reordered');
    }

    /**
     * Confirm deletion of an item.
     */
    public function confirmDelete(int $itemId): void
    {
        $this->deletingItemId = $itemId;
    }

    /**
     * Delete the confirmed item.
     */
    public function deleteItem(): void
    {
        if ($this->deletingItemId === null) {
            return;
        }

        $item = KanbanItem::findOrFail($this->deletingItemId);
        $title = $item->title;
        $item->delete();
        $this->deletingItemId = null;

        app(NotificationService::class)->notifyKanbanItemDeleted($title, auth()->user());

        $this->dispatch('item-deleted');
        $this->dispatch('flux-toast', message: __('Task deleted successfully 🗑️'));
    }

    /**
     * Cancel the delete confirmation.
     */
    public function cancelDelete(): void
    {
        $this->deletingItemId = null;
    }

    /**
     * Open the view modal for the given item.
     */
    public function viewItem(int $id): void
    {
        $this->viewingItemId = $id;
        $this->newComment = null;
        $this->resetValidation();
    }

    /**
     * Close the view modal and reset the reply form.
     */
    public function closeViewModal(): void
    {
        $this->viewingItemId = null;
        $this->newComment = null;
        $this->resetValidation();
    }

    /**
     * Open the currently viewed item in the edit modal.
     */
    public function editViewedItem(): void
    {
        $item = $this->viewedItem;

        if ($item === null) {
            return;
        }

        $this->closeViewModal();
        $this->editingItemId = $item->id;
        $this->title = $item->title;
        $this->description = $item->description;
        $this->showModal = true;
    }

    /**
     * Add a comment to the currently viewed kanban item.
     */
    public function addComment(): void
    {
        $validated = $this->validate([
            'newComment' => 'required|string|max:2000',
        ]);

        if ($this->viewingItemId === null) {
            return;
        }

        KanbanItemComment::create([
            'kanban_item_id' => $this->viewingItemId,
            'user_id' => auth()->id(),
            'content' => $validated['newComment'],
        ]);

        $this->newComment = null;
        $this->dispatch('flux-toast', message: __('Reply added successfully 💬'));
    }

    /**
     * Close the create/edit modal and reset the form.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Reset the form fields.
     */
    private function resetForm(): void
    {
        $this->editingItemId = null;
        $this->title = '';
        $this->description = null;
        $this->resetValidation();
    }

    /**
     * Get the color theme for the given status column.
     */
    public function columnColor(KanbanStatus $status): string
    {
        return match ($status) {
            KanbanStatus::Todo => 'blue',
            KanbanStatus::InProgress => 'amber',
            KanbanStatus::Done => 'green',
            KanbanStatus::AppliedInSystem => 'purple',
        };
    }

    /**
     * Get the icon for the given status column.
     */
    public function columnIcon(KanbanStatus $status): string
    {
        return match ($status) {
            KanbanStatus::Todo => 'clipboard-document-list',
            KanbanStatus::InProgress => 'bolt',
            KanbanStatus::Done => 'check-circle',
            KanbanStatus::AppliedInSystem => 'server',
        };
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Kanban Board') }}</flux:heading>

            <div class="flex gap-2">
                <flux:button variant="outline" icon="arrow-path" wire:click="$refresh">
                    {{ __('Refresh') }}
                </flux:button>

                <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                    {{ __('Add Task') }}
                </flux:button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach (App\Enums\KanbanStatus::ordered() as $status)
                @php
                    $color = $this->columnColor($status);
                    $icon = $this->columnIcon($status);
                    $colorClasses = match ($color) {
                        'blue' => 'border-blue-200 bg-blue-50/50 dark:border-blue-800 dark:bg-blue-950/20',
                        'amber' => 'border-amber-200 bg-amber-50/50 dark:border-amber-800 dark:bg-amber-950/20',
                        'green' => 'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-950/20',
                        'purple' => 'border-purple-200 bg-purple-50/50 dark:border-purple-800 dark:bg-purple-950/20',
                        default => 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900',
                    };
                    $headerClasses = match ($color) {
                        'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                        'green' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                        'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
                        default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                    };
                    $cardBorderClasses = match ($color) {
                        'blue' => 'border-blue-200 dark:border-blue-800/60',
                        'amber' => 'border-amber-200 dark:border-amber-800/60',
                        'green' => 'border-green-200 dark:border-green-800/60',
                        'purple' => 'border-purple-200 dark:border-purple-800/60',
                        default => 'border-zinc-200 dark:border-zinc-700',
                    };
                    $leftBorderClasses = match ($color) {
                        'blue' => 'border-l-blue-400',
                        'amber' => 'border-l-amber-400',
                        'green' => 'border-l-green-400',
                        'purple' => 'border-l-purple-400',
                        default => 'border-l-zinc-400',
                    };
                @endphp

                <flux:card class="flex h-full flex-col {{ $colorClasses }}">
                    <div class="mb-4 flex items-center justify-between rounded-lg px-3 py-2 {{ $headerClasses }}">
                        <div class="flex items-center gap-2">
                            <flux:icon name="{{ $icon }}" class="size-5" />
                            <flux:heading level="2">{{ $status->label() }}</flux:heading>
                        </div>
                        <flux:badge size="sm" color="{{ $color }}">{{ $this->columns[$status->value]->count() }}</flux:badge>
                    </div>

                    <div class="flex flex-1 flex-col gap-3">
                        @forelse ($this->columns[$status->value] as $item)
                            <div
                                wire:key="kanban-item-{{ $item->id }}"
                                wire:click="viewItem({{ $item->id }})"
                                class="group flex cursor-pointer flex-col gap-2 rounded-lg border border-l-4 bg-white p-3 shadow-sm transition hover:shadow-md dark:bg-zinc-800 {{ $cardBorderClasses }} {{ $leftBorderClasses }}"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <flux:heading level="3" class="text-sm font-semibold">{{ $item->title }}</flux:heading>
                                </div>

                                @if (filled($item->description))
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ Str::limit($item->description, 120) }}
                                    </flux:text>
                                @endif

                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    @php
                                        $statuses = App\Enums\KanbanStatus::ordered();
                                        $currentIndex = array_search($status, $statuses, true);
                                        $previousStatus = $currentIndex > 0 ? $statuses[$currentIndex - 1] : null;
                                        $nextStatus = $currentIndex < count($statuses) - 1 ? $statuses[$currentIndex + 1] : null;
                                    @endphp

                                    @if ($previousStatus !== null)
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="arrow-left"
                                            wire:click.stop="moveToColumn({{ $item->id }}, '{{ $previousStatus->value }}')"
                                        />
                                    @endif

                                    @if ($nextStatus !== null)
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="arrow-right"
                                            wire:click.stop="moveToColumn({{ $item->id }}, '{{ $nextStatus->value }}')"
                                        />
                                    @endif

                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="arrow-up"
                                        wire:click.stop="moveUp({{ $item->id }})"
                                    />

                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="arrow-down"
                                        wire:click.stop="moveDown({{ $item->id }})"
                                    />

                                    <div class="ms-auto flex items-center gap-1">
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="eye"
                                            wire:click.stop="viewItem({{ $item->id }})"
                                        />

                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="pencil-square"
                                            wire:click.stop="editItem({{ $item->id }})"
                                        />

                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click.stop="confirmDelete({{ $item->id }})"
                                        />
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-4 text-center {{ $cardBorderClasses }}">
                                <flux:icon name="{{ $icon }}" class="size-8 opacity-40" />
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('No tasks yet') }}
                                </flux:text>
                            </div>
                        @endforelse
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>

    <flux:modal wire:model="showModal" class="w-full max-w-lg">
        <flux:heading level="2">
            {{ $editingItemId === null ? __('Add Task') : __('Edit Task') }}
        </flux:heading>

        <form wire:submit="saveItem" class="mt-4 space-y-4">
            <flux:field>
                <flux:label>{{ __('Title') }}</flux:label>
                <flux:input wire:model="title" placeholder="{{ __('Task title') }}" />
                <flux:error name="title" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="description" placeholder="{{ __('Optional description') }}" rows="4" />
                <flux:error name="description" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="deletingItemId" class="w-full max-w-sm">
        <flux:heading level="2">{{ __('Delete Task') }}</flux:heading>

        <flux:text class="mt-2">
            {{ __('Are you sure you want to delete this task? This action cannot be undone.') }}
        </flux:text>

        <div class="mt-6 flex justify-end gap-2">
            <flux:button type="button" variant="ghost" wire:click="cancelDelete">
                {{ __('Cancel') }}
            </flux:button>

            <flux:button type="button" variant="danger" wire:click="deleteItem">
                {{ __('Delete') }}
            </flux:button>
        </div>
    </flux:modal>

    <flux:modal wire:model="viewingItemId" class="w-full max-w-2xl">
        @if ($this->viewedItem !== null)
            @php
                $item = $this->viewedItem;
                $viewColor = $this->columnColor($item->status);
            @endphp

            <div class="space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <flux:heading level="2">{{ $item->title }}</flux:heading>

                        <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                            <flux:badge size="sm" color="{{ $viewColor }}">{{ $item->status->label() }}</flux:badge>

                            <span>{{ __('Created by :name', ['name' => $item->creator->name]) }}</span>

                            <span>·</span>

                            <span>{{ $item->created_at->diffForHumans() }}</span>
                        </div>
                    </div>

                    <flux:button type="button" variant="ghost" icon="pencil-square" wire:click="editViewedItem" size="sm">
                        {{ __('Edit') }}
                    </flux:button>
                </div>

                @if (filled($item->description))
                    <flux:text class="whitespace-pre-wrap">{{ $item->description }}</flux:text>
                @endif

                <flux:separator />

                <div class="space-y-3">
                    <flux:heading level="3">{{ __('Replies') }}</flux:heading>

                    @if ($item->comments->isEmpty())
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('No replies yet. Be the first to respond.') }}
                        </flux:text>
                    @else
                        <div class="max-h-80 space-y-3 overflow-y-auto">
                            @foreach ($item->comments as $comment)
                                <div wire:key="kanban-comment-{{ $comment->id }}" class="flex gap-3">
                                    <flux:avatar size="sm" class="shrink-0">
                                        {{ $comment->user->initials() }}
                                    </flux:avatar>

                                    <div class="flex-1 space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium">{{ $comment->user->name }}</span>
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $comment->created_at->diffForHumans() }}</span>
                                        </div>

                                        <flux:text class="text-sm">{{ $comment->content }}</flux:text>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <form wire:submit="addComment" class="space-y-3">
                    <flux:field>
                        <flux:label>{{ __('Add a reply') }}</flux:label>
                        <flux:textarea wire:model="newComment" placeholder="{{ __('Write your reply...') }}" rows="3" />
                        <flux:error name="newComment" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="paper-airplane">
                            {{ __('Reply') }}
                        </flux:button>
                    </div>
                </form>
            </div>
        @endif
    </flux:modal>
</div>
