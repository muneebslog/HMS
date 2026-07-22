<?php

use App\Enums\KanbanStatus;
use App\Models\KanbanItem;
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
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Kanban Board') }}</flux:heading>

            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                {{ __('Add Task') }}
            </flux:button>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach (App\Enums\KanbanStatus::ordered() as $status)
                <flux:card class="flex h-full flex-col">
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading level="2">{{ $status->label() }}</flux:heading>
                        <flux:badge size="sm" color="zinc">{{ $this->columns[$status->value]->count() }}</flux:badge>
                    </div>

                    <div class="flex flex-1 flex-col gap-3">
                        @forelse ($this->columns[$status->value] as $item)
                            <div
                                wire:key="kanban-item-{{ $item->id }}"
                                class="group flex flex-col gap-2 rounded-lg border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
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
                                            wire:click="moveToColumn({{ $item->id }}, '{{ $previousStatus->value }}')"
                                        />
                                    @endif

                                    @if ($nextStatus !== null)
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="arrow-right"
                                            wire:click="moveToColumn({{ $item->id }}, '{{ $nextStatus->value }}')"
                                        />
                                    @endif

                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="arrow-up"
                                        wire:click="moveUp({{ $item->id }})"
                                    />

                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="arrow-down"
                                        wire:click="moveDown({{ $item->id }})"
                                    />

                                    <div class="ms-auto flex items-center gap-1">
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="pencil-square"
                                            wire:click="editItem({{ $item->id }})"
                                        />

                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click="confirmDelete({{ $item->id }})"
                                        />
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-1 items-center justify-center rounded-lg border border-dashed border-zinc-300 p-4 text-center dark:border-zinc-700">
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
</div>
