<?php

use App\Enums\PolicyJournalStatus;
use App\Models\PolicyJournal;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Policy Journal')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = '';

    public string $statusFilter = '';

    public ?int $editingId = null;

    #[Validate]
    public string $title = '';

    #[Validate]
    public string $incident = '';

    #[Validate]
    public string $resolution = '';

    #[Validate]
    public string $policy = '';

    #[Validate]
    public string $category = '';

    #[Validate]
    public string $tags = '';

    #[Validate]
    public ?string $effectiveDate = null;

    #[Validate]
    public ?string $reviewDate = null;

    #[Validate]
    public string $status = '';

    /**
     * @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    #[Validate]
    public array $newAttachments = [];

    /**
     * @var list<array{path: string, original_name: string, size: int}>
     */
    public array $existingAttachments = [];

    public bool $showModal = false;

    public ?int $viewingId = null;

    public ?int $deletingId = null;

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
     * Get the validation rules for the form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'incident' => ['required', 'string', 'max:5000'],
            'resolution' => ['required', 'string', 'max:5000'],
            'policy' => ['required', 'string', 'max:10000'],
            'category' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'string', 'max:500'],
            'effectiveDate' => ['nullable', 'date'],
            'reviewDate' => ['nullable', 'date', 'after_or_equal:effectiveDate'],
            'status' => ['required', 'string', 'in:draft,active,archived'],
            'newAttachments' => ['nullable', 'array', 'max:5'],
            'newAttachments.*' => ['file', 'max:5120', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp'],
        ];
    }

    /**
     * Reset pagination when the search term changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the category filter changes.
     */
    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the status filter changes.
     */
    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Get the paginated, filtered policy journal entries.
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $status = PolicyJournalStatus::tryFrom($this->statusFilter);

        return PolicyJournal::query()
            ->with('creator')
            ->search($this->search)
            ->filterByCategory($this->categoryFilter)
            ->filterByStatus($status)
            ->latest()
            ->paginate(12);
    }

    /**
     * Get the entry currently being viewed.
     */
    #[Computed]
    public function viewedEntry(): ?PolicyJournal
    {
        if ($this->viewingId === null) {
            return null;
        }

        return PolicyJournal::with('creator')->find($this->viewingId);
    }

    /**
     * Get distinct categories ordered alphabetically.
     *
     * @return list<string>
     */
    #[Computed]
    public function categories(): array
    {
        return PolicyJournal::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /**
     * Open the modal to create a new entry.
     */
    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->status = PolicyJournalStatus::Active->value;
        $this->showModal = true;
    }

    /**
     * Open the modal to edit an existing entry.
     */
    public function editEntry(int $id): void
    {
        $entry = PolicyJournal::findOrFail($id);

        $this->editingId = $entry->id;
        $this->title = $entry->title;
        $this->incident = $entry->incident;
        $this->resolution = $entry->resolution;
        $this->policy = $entry->policy;
        $this->category = $entry->category ?? '';
        $this->tags = is_array($entry->tags) ? implode(', ', $entry->tags) : '';
        $this->effectiveDate = $entry->effective_date?->format('Y-m-d');
        $this->reviewDate = $entry->review_date?->format('Y-m-d');
        $this->status = $entry->status->value;
        $this->existingAttachments = $entry->attachments ?? [];
        $this->newAttachments = [];
        $this->showModal = true;
        $this->viewingId = null;
    }

    /**
     * Open the view modal for the given entry.
     */
    public function viewEntry(int $id): void
    {
        $this->viewingId = $id;
        $this->resetValidation();
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
     * Close the view modal.
     */
    public function closeViewModal(): void
    {
        $this->viewingId = null;
    }

    /**
     * Save a new or edited policy journal entry.
     */
    public function saveEntry(): void
    {
        $this->validate();

        $data = [
            'title' => $this->title,
            'incident' => $this->incident,
            'resolution' => $this->resolution,
            'policy' => $this->policy,
            'category' => $this->category ?: null,
            'tags' => $this->parsedTags(),
            'effective_date' => $this->effectiveDate ?: null,
            'review_date' => $this->reviewDate ?: null,
            'status' => PolicyJournalStatus::from($this->status),
        ];

        if ($this->editingId === null) {
            $entry = PolicyJournal::create([
                ...$data,
                'created_by' => auth()->id(),
                'attachments' => [],
            ]);

            $this->storeAttachments($entry);

            Flux::toast(variant: 'success', text: __('Policy journal entry added successfully.'));
        } else {
            $entry = PolicyJournal::findOrFail($this->editingId);
            $entry->update($data);

            $entry->attachments = $this->existingAttachments;
            $entry->save();

            $this->storeAttachments($entry);

            Flux::toast(variant: 'success', text: __('Policy journal entry updated successfully.'));
        }

        $this->closeModal();
    }

    /**
     * Confirm deletion of an entry.
     */
    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    /**
     * Delete the confirmed entry and its stored attachments.
     */
    public function deleteEntry(): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $entry = PolicyJournal::findOrFail($this->deletingId);

        foreach ($entry->attachments ?? [] as $attachment) {
            Storage::disk('local')->delete($attachment['path']);
        }

        $entry->delete();
        $this->deletingId = null;
        $this->viewingId = null;

        Flux::toast(variant: 'success', text: __('Policy journal entry deleted successfully.'));
    }

    /**
     * Cancel the delete confirmation.
     */
    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    /**
     * Remove an existing attachment from the entry being edited.
     */
    public function removeAttachment(int $index): void
    {
        $attachment = $this->existingAttachments[$index] ?? null;

        if ($attachment === null) {
            return;
        }

        Storage::disk('local')->delete($attachment['path']);
        unset($this->existingAttachments[$index]);
        $this->existingAttachments = array_values($this->existingAttachments);
    }

    /**
     * Clear all search and filter inputs.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->categoryFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    /**
     * Reset the form fields.
     */
    private function resetForm(): void
    {
        $this->editingId = null;
        $this->title = '';
        $this->incident = '';
        $this->resolution = '';
        $this->policy = '';
        $this->category = '';
        $this->tags = '';
        $this->effectiveDate = null;
        $this->reviewDate = null;
        $this->status = '';
        $this->newAttachments = [];
        $this->existingAttachments = [];
        $this->resetValidation();
    }

    /**
     * Parse the comma-separated tags into an array.
     *
     * @return list<string>
     */
    private function parsedTags(): array
    {
        return collect(explode(',', $this->tags))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Store newly uploaded attachments for the given entry.
     */
    private function storeAttachments(PolicyJournal $entry): void
    {
        $attachments = $entry->attachments ?? [];

        foreach ($this->newAttachments as $file) {
            $path = $file->store("policy-journals/{$entry->id}", 'local');

            $attachments[] = [
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ];
        }

        $entry->attachments = $attachments;
        $entry->save();

        $this->newAttachments = [];
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Policy Journal') }}</flux:heading>

            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                {{ __('Add Policy Entry') }}
            </flux:button>
        </div>

        <flux:card>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('Search') }}</flux:label>
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search incidents, resolutions, policies...') }}"
                        icon="magnifying-glass"
                    />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:select wire:model.live="categoryFilter">
                        <option value="">{{ __('All categories') }}</option>
                        @foreach ($this->categories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Status') }}</flux:label>
                    <flux:select wire:model.live="statusFilter">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach (App\Enums\PolicyJournalStatus::ordered() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
            </div>

            @if (filled($search) || filled($categoryFilter) || filled($statusFilter))
                <div class="mt-4 flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="clearFilters">
                        {{ __('Clear filters') }}
                    </flux:button>
                </div>
            @endif
        </flux:card>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->entries as $entry)
                <div
                    wire:key="policy-journal-{{ $entry->id }}"
                    wire:click="viewEntry({{ $entry->id }})"
                    class="group flex cursor-pointer flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <div class="flex items-start justify-between gap-3">
                        <flux:heading level="3" class="text-base font-semibold">{{ $entry->title }}</flux:heading>
                        <flux:badge size="sm" color="{{ $entry->status === App\Enums\PolicyJournalStatus::Active ? 'green' : ($entry->status === App\Enums\PolicyJournalStatus::Draft ? 'amber' : 'zinc') }}">
                            {{ $entry->status->label() }}
                        </flux:badge>
                    </div>

                    @if (filled($entry->category))
                        <div>
                            <flux:badge size="sm" color="blue" variant="outline">{{ $entry->category }}</flux:badge>
                        </div>
                    @endif

                    <flux:text class="line-clamp-3 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ Str::limit($entry->policy, 180) }}
                    </flux:text>

                    @if (is_array($entry->tags) && count($entry->tags) > 0)
                        <div class="flex flex-wrap gap-1">
                            @foreach (array_slice($entry->tags, 0, 4) as $tag)
                                <flux:badge size="sm" color="zinc" variant="ghost">{{ $tag }}</flux:badge>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-auto flex items-center justify-between border-t border-zinc-100 pt-3 text-xs text-zinc-400 dark:border-zinc-700">
                        <span>{{ __('By :name', ['name' => $entry->creator->name]) }}</span>
                        <span>{{ $entry->created_at->diffForHumans() }}</span>
                    </div>
                </div>
            @empty
                <div class="col-span-full flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 py-16 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon name="book-open" class="size-10 text-zinc-400" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ __('No policy entries found.') }}
                    </flux:text>
                </div>
            @endforelse
        </div>

        <div class="mt-2">
            {{ $this->entries->links() }}
        </div>
    </div>

    <flux:modal wire:model="showModal" class="w-full max-w-2xl">
        <flux:heading level="2">
            {{ $editingId === null ? __('Add Policy Entry') : __('Edit Policy Entry') }}
        </flux:heading>

        <form wire:submit="saveEntry" class="mt-4 space-y-4">
            <flux:field>
                <flux:label>{{ __('Title') }}</flux:label>
                <flux:input wire:model="title" placeholder="{{ __('Short, searchable title') }}" />
                <flux:error name="title" />
            </flux:field>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:input wire:model="category" placeholder="{{ __('e.g., Reception, Billing') }}" />
                    <flux:error name="category" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Tags') }}</flux:label>
                    <flux:input wire:model="tags" placeholder="{{ __('Comma, separated, tags') }}" />
                    <flux:error name="tags" />
                </flux:field>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:field>
                    <flux:label>{{ __('Status') }}</flux:label>
                    <flux:select wire:model="status">
                        @foreach (App\Enums\PolicyJournalStatus::ordered() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="status" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Effective Date') }}</flux:label>
                    <flux:input type="date" wire:model="effectiveDate" />
                    <flux:error name="effectiveDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Review Date') }}</flux:label>
                    <flux:input type="date" wire:model="reviewDate" />
                    <flux:error name="reviewDate" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('What happened?') }}</flux:label>
                <flux:textarea wire:model="incident" placeholder="{{ __('Describe the incident or situation') }}" rows="4" />
                <flux:error name="incident" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('How was it resolved?') }}</flux:label>
                <flux:textarea wire:model="resolution" placeholder="{{ __('Describe the resolution steps') }}" rows="4" />
                <flux:error name="resolution" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Resulting policy / solution') }}</flux:label>
                <flux:textarea wire:model="policy" placeholder="{{ __('Document the policy or solution to prevent recurrence') }}" rows="5" />
                <flux:error name="policy" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Attachments') }}</flux:label>
                <flux:input type="file" wire:model="newAttachments" multiple />
                <flux:error name="newAttachments.*" />

                @if (count($newAttachments) > 0)
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($newAttachments as $index => $file)
                            <flux:badge size="sm" color="blue" variant="outline">
                                {{ $file->getClientOriginalName() }}
                            </flux:badge>
                        @endforeach
                    </div>
                @endif

                @if (count($existingAttachments) > 0)
                    <div class="mt-3 space-y-2">
                        <flux:text class="text-sm font-medium">{{ __('Existing attachments') }}</flux:text>
                        @foreach ($existingAttachments as $index => $attachment)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                <span class="text-sm">{{ $attachment['original_name'] }}</span>
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click.stop="removeAttachment({{ $index }})"
                                >
                                    {{ __('Remove') }}
                                </flux:button>
                            </div>
                        @endforeach
                    </div>
                @endif
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

    <flux:modal wire:model="viewingId" class="w-full max-w-3xl">
        @if ($this->viewedEntry !== null)
            @php
                $entry = $this->viewedEntry;
                $statusColor = match ($entry->status) {
                    App\Enums\PolicyJournalStatus::Active => 'green',
                    App\Enums\PolicyJournalStatus::Draft => 'amber',
                    App\Enums\PolicyJournalStatus::Archived => 'zinc',
                };
            @endphp

            <div class="space-y-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-2">
                        <flux:heading level="2">{{ $entry->title }}</flux:heading>

                        <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                            <flux:badge size="sm" color="{{ $statusColor }}">{{ $entry->status->label() }}</flux:badge>

                            @if (filled($entry->category))
                                <flux:badge size="sm" color="blue" variant="outline">{{ $entry->category }}</flux:badge>
                            @endif

                            <span>{{ __('By :name', ['name' => $entry->creator->name]) }}</span>

                            <span>·</span>

                            <span>{{ $entry->created_at->diffForHumans() }}</span>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <flux:button type="button" variant="ghost" icon="pencil-square" wire:click="editEntry({{ $entry->id }})" size="sm">
                            {{ __('Edit') }}
                        </flux:button>

                        <flux:button type="button" variant="ghost" icon="trash" wire:click="confirmDelete({{ $entry->id }})" size="sm">
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </div>

                @if (is_array($entry->tags) && count($entry->tags) > 0)
                    <div class="flex flex-wrap gap-1">
                        @foreach ($entry->tags as $tag)
                            <flux:badge size="sm" color="zinc" variant="ghost">{{ $tag }}</flux:badge>
                        @endforeach
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @if ($entry->effective_date !== null)
                        <div class="text-sm">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Effective:') }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $entry->effective_date->format('M d, Y') }}</span>
                        </div>
                    @endif

                    @if ($entry->review_date !== null)
                        <div class="text-sm">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Review:') }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $entry->review_date->format('M d, Y') }}</span>
                        </div>
                    @endif
                </div>

                <flux:separator />

                <div class="space-y-2">
                    <flux:heading level="3" class="text-sm">{{ __('What happened?') }}</flux:heading>
                    <flux:text class="whitespace-pre-wrap">{{ $entry->incident }}</flux:text>
                </div>

                <div class="space-y-2">
                    <flux:heading level="3" class="text-sm">{{ __('How was it resolved?') }}</flux:heading>
                    <flux:text class="whitespace-pre-wrap">{{ $entry->resolution }}</flux:text>
                </div>

                <div class="space-y-2">
                    <flux:heading level="3" class="text-sm">{{ __('Resulting policy / solution') }}</flux:heading>
                    <flux:text class="whitespace-pre-wrap">{{ $entry->policy }}</flux:text>
                </div>

                @if (is_array($entry->attachments) && count($entry->attachments) > 0)
                    <flux:separator />

                    <div class="space-y-2">
                        <flux:heading level="3" class="text-sm">{{ __('Attachments') }}</flux:heading>

                        <div class="space-y-2">
                            @foreach ($entry->attachments as $index => $attachment)
                                <a
                                    href="{{ route('admin.policy-journals.download', ['policyJournal' => $entry->id, 'index' => $index]) }}"
                                    class="flex items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                    target="_blank"
                                >
                                    <flux:icon name="document" class="size-5 text-zinc-400" />
                                    <span class="text-sm">{{ $attachment['original_name'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model="deletingId" class="w-full max-w-sm">
        <flux:heading level="2">{{ __('Delete Policy Entry') }}</flux:heading>

        <flux:text class="mt-2">
            {{ __('Are you sure you want to delete this policy entry? This action cannot be undone.') }}
        </flux:text>

        <div class="mt-6 flex justify-end gap-2">
            <flux:button type="button" variant="ghost" wire:click="cancelDelete">
                {{ __('Cancel') }}
            </flux:button>

            <flux:button type="button" variant="danger" wire:click="deleteEntry">
                {{ __('Delete') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
