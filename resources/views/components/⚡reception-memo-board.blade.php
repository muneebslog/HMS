<?php

use App\Models\ReceptionMemo;
use App\Models\ReceptionMemoRead;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $title = '';

    public string $body = '';

    public bool $showCreateForm = false;

    /**
     * Confirmation phrases typed per memo id.
     *
     * @var array<int, string>
     */
    public array $confirmations = [];

    /**
     * Restrict the component to admin and receptionist users.
     */
    public function mount(): void
    {
        $this->ensureAuthorized();
    }

    /**
     * Get memos unread by the current user.
     *
     * @return Collection<int, ReceptionMemo>
     */
    #[Computed]
    public function memos(): Collection
    {
        return ReceptionMemo::query()
            ->with('creator')
            ->unreadFor(auth()->user())
            ->latest()
            ->get();
    }

    /**
     * Show the create-memo form.
     */
    public function openCreateForm(): void
    {
        $this->ensureAuthorized();

        $this->showCreateForm = true;
        $this->title = '';
        $this->body = '';
        $this->resetValidation();
    }

    /**
     * Hide the create-memo form.
     */
    public function closeCreateForm(): void
    {
        $this->showCreateForm = false;
        $this->title = '';
        $this->body = '';
        $this->resetValidation();
    }

    /**
     * Post a new memo to the shared board.
     */
    public function createMemo(): void
    {
        $this->ensureAuthorized();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = auth()->user();

        $memo = ReceptionMemo::create([
            'created_by' => $user->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'color' => 'amber',
        ]);

        app(NotificationService::class)->notifyReceptionMemoCreated($memo, $user);

        $this->showCreateForm = false;
        $this->title = '';
        $this->body = '';
        unset($this->memos);
    }

    /**
     * Mark a memo as read after confirming the required phrase.
     */
    public function markAsRead(int $memoId): void
    {
        $this->ensureAuthorized();

        $field = "confirmations.{$memoId}";

        $this->validate([
            $field => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (Str::lower(trim((string) $value)) !== ReceptionMemo::READ_CONFIRMATION) {
                        $fail(__('Type ":phrase" to mark this memo as read.', [
                            'phrase' => ReceptionMemo::READ_CONFIRMATION,
                        ]));
                    }
                },
            ],
        ]);

        $memo = ReceptionMemo::find($memoId);

        if ($memo === null) {
            return;
        }

        $user = auth()->user();

        if ($memo->isReadBy($user)) {
            return;
        }

        ReceptionMemoRead::create([
            'reception_memo_id' => $memo->id,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        unset($this->confirmations[$memoId], $this->memos);
    }

    /**
     * Ensure the current user may use this component.
     */
    private function ensureAuthorized(): void
    {
        $user = auth()->user();

        if ($user === null || (! $user->isAdmin() && ! $user->isReceptionist())) {
            abort(403);
        }
    }
}; ?>

<div class="space-y-4" wire:poll.10s>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:heading level="2">{{ __('Memo Board') }}</flux:heading>

            @if ($this->memos->isNotEmpty())
                <flux:badge size="sm" color="amber">{{ $this->memos->count() }}</flux:badge>
            @endif
        </div>

        <flux:button size="sm" variant="primary" icon="plus" wire:click="openCreateForm">
            {{ __('Add Memo') }}
        </flux:button>
    </div>

    @if ($showCreateForm)
        <form wire:submit="createMemo" class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
            <flux:field>
                <flux:label>{{ __('Title') }}</flux:label>
                <flux:input wire:model="title" placeholder="{{ __('Memo title') }}" />
                <flux:error name="title" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Message') }}</flux:label>
                <flux:textarea wire:model="body" placeholder="{{ __('Write the memo...') }}" rows="3" />
                <flux:error name="body" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" wire:click="closeCreateForm">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" size="sm" variant="primary" icon="bookmark">
                    {{ __('Post Memo') }}
                </flux:button>
            </div>
        </form>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->memos as $memo)
            <div
                wire:key="memo-card-{{ $memo->id }}"
                class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 shadow-sm dark:border-amber-700 dark:bg-amber-950/40"
            >
                <div class="flex items-start justify-between gap-2">
                    <flux:heading level="3" class="text-base">{{ $memo->title }}</flux:heading>
                    <flux:icon name="bookmark" class="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                </div>

                <flux:text class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">{{ $memo->body }}</flux:text>

                <flux:text class="text-xs text-zinc-500">
                    {{ $memo->creator->name }}
                    &middot;
                    {{ $memo->created_at->diffForHumans() }}
                </flux:text>

                <form wire:submit="markAsRead({{ $memo->id }})" class="mt-auto space-y-2 border-t border-amber-200 pt-3 dark:border-amber-800">
                    <flux:field>
                        <flux:label class="text-xs">{{ __('Type ":phrase" to dismiss', ['phrase' => \App\Models\ReceptionMemo::READ_CONFIRMATION]) }}</flux:label>
                        <flux:input
                            wire:model="confirmations.{{ $memo->id }}"
                            placeholder="{{ \App\Models\ReceptionMemo::READ_CONFIRMATION }}"
                            size="sm"
                        />
                        <flux:error name="confirmations.{{ $memo->id }}" />
                    </flux:field>

                    <flux:button type="submit" size="sm" variant="ghost" icon="check" class="w-full">
                        {{ __('Confirm Read') }}
                    </flux:button>
                </form>
            </div>
        @empty
            <div class="col-span-full rounded-lg border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
                <flux:text class="text-zinc-500">{{ __('No unread memos.') }}</flux:text>
            </div>
        @endforelse
    </div>
</div>
