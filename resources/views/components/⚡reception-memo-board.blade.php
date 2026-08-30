<?php

use App\Enums\ReceptionMemoColor;
use App\Models\ReceptionMemo;
use App\Models\ReceptionMemoRead;
use App\Services\NotificationService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public string $title = '';

    public string $body = '';

    public string $color = 'amber';

    public bool $showCreateForm = false;

    public string $view = 'unread';

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
     * Refresh the memo list when a new memo is broadcast.
     */
    #[On('echo-private:hms.reception,.memo.posted')]
    public function refreshMemos(): void
    {
        unset($this->memos, $this->readMemos, $this->audienceCount);
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
            ->withCount('reads')
            ->unreadFor(auth()->user())
            ->latest()
            ->get();
    }

    /**
     * Get memos recently read by the current user.
     *
     * @return Collection<int, ReceptionMemo>
     */
    #[Computed]
    public function readMemos(): Collection
    {
        return ReceptionMemo::query()
            ->with(['creator', 'reads' => fn ($query) => $query->where('user_id', auth()->id())])
            ->readFor(auth()->user())
            ->join('reception_memo_reads', function ($join) {
                $join->on('reception_memos.id', '=', 'reception_memo_reads.reception_memo_id')
                    ->where('reception_memo_reads.user_id', auth()->id());
            })
            ->orderByDesc('reception_memo_reads.read_at')
            ->select('reception_memos.*')
            ->limit(12)
            ->get();
    }

    /**
     * Count users who can see memos on the board.
     */
    #[Computed]
    public function audienceCount(): int
    {
        return ReceptionMemo::audienceCount();
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
        $this->color = ReceptionMemoColor::Amber->value;
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
        $this->color = ReceptionMemoColor::Amber->value;
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
            'color' => ['required', Rule::enum(ReceptionMemoColor::class)],
        ]);

        $user = auth()->user();

        $memo = ReceptionMemo::create([
            'created_by' => $user->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'color' => $validated['color'],
        ]);

        app(NotificationService::class)->notifyReceptionMemoCreated($memo, $user);

        $this->showCreateForm = false;
        $this->title = '';
        $this->body = '';
        $this->color = ReceptionMemoColor::Amber->value;
        unset($this->memos, $this->readMemos);

        Flux::toast(variant: 'success', text: __('Memo posted to the board.'));
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

        unset($this->confirmations[$memoId], $this->memos, $this->readMemos);

        Flux::toast(variant: 'success', text: __('Memo dismissed.'));
    }

    /**
     * Delete a memo from the board.
     */
    public function deleteMemo(int $memoId): void
    {
        $this->ensureAuthorized();

        $memo = ReceptionMemo::find($memoId);

        if ($memo === null) {
            return;
        }

        $user = auth()->user();

        if (! $memo->canBeDeletedBy($user)) {
            abort(403);
        }

        $memo->delete();

        unset($this->memos, $this->readMemos);

        Flux::toast(variant: 'success', text: __('Memo deleted.'));
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

<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading level="2">{{ __('Memo Board') }}</flux:heading>

            @if ($view === 'unread' && $this->memos->isNotEmpty())
                <flux:badge size="sm" color="amber">{{ $this->memos->count() }}</flux:badge>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:radio.group wire:model.live="view" variant="segmented" size="sm">
                <flux:radio value="unread">{{ __('Unread') }}</flux:radio>
                <flux:radio value="history">{{ __('History') }}</flux:radio>
            </flux:radio.group>

            <flux:button size="sm" variant="primary" icon="plus" wire:click="openCreateForm">
                {{ __('Add Memo') }}
            </flux:button>
        </div>
    </div>

    <flux:modal wire:model="showCreateForm" class="md:max-w-lg">
        <form wire:submit="createMemo" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Post a Memo') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Share a note with reception staff. Everyone must acknowledge it before it disappears from their board.') }}</flux:text>
            </div>

            <div class="space-y-3 rounded-lg border p-4 {{ ReceptionMemoColor::from($color)->formPanelClasses() }}">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="title" placeholder="{{ __('Memo title') }}" />
                    <flux:error name="title" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Message') }}</flux:label>
                    <flux:textarea wire:model="body" placeholder="{{ __('Write the memo...') }}" rows="4" />
                    <flux:error name="body" />
                    <flux:description>{{ __(':count / 5000 characters', ['count' => strlen($body)]) }}</flux:description>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Color') }}</flux:label>
                    <div class="grid grid-cols-4 gap-2 sm:grid-cols-4">
                        @foreach (ReceptionMemoColor::cases() as $memoColor)
                            <button
                                type="button"
                                wire:click="$set('color', '{{ $memoColor->value }}')"
                                @class([
                                    'flex flex-col items-center gap-1.5 rounded-lg border p-2 transition',
                                    $color === $memoColor->value
                                        ? 'border-zinc-400 bg-white shadow-sm ring-2 ring-zinc-400 dark:border-zinc-500 dark:bg-zinc-800 dark:ring-zinc-500'
                                        : 'border-transparent hover:bg-white/60 dark:hover:bg-zinc-800/60',
                                ])
                            >
                                <span class="size-7 rounded-full {{ $memoColor->swatchClasses() }} ring-2 ring-offset-2 ring-offset-transparent {{ $color === $memoColor->value ? 'ring-current scale-110' : 'ring-transparent' }}"></span>
                                <span class="text-[10px] font-medium text-zinc-600 dark:text-zinc-400">{{ $memoColor->label() }}</span>
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="color" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" wire:click="closeCreateForm">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" size="sm" variant="primary" icon="bookmark" wire:loading.attr="disabled" wire:target="createMemo">
                    <span wire:loading.remove wire:target="createMemo">{{ __('Post Memo') }}</span>
                    <span wire:loading wire:target="createMemo">{{ __('Posting...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($view === 'unread')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->memos as $memo)
                @php($memoColor = $memo->color instanceof ReceptionMemoColor ? $memo->color : ReceptionMemoColor::from($memo->color))
                <div
                    wire:key="memo-card-{{ $memo->id }}"
                    @class([
                        'group flex flex-col gap-3 rounded-xl border p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md',
                        $memoColor->cardClasses(),
                        match ($memo->id % 3) {
                            0 => '-rotate-1',
                            1 => 'rotate-0',
                            default => 'rotate-1',
                        },
                    ])
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 space-y-1">
                            <flux:heading level="3" class="text-base leading-snug">{{ $memo->title }}</flux:heading>
                            <flux:badge size="sm" class="{{ $memoColor->swatchClasses() }} !text-white">{{ $memoColor->label() }}</flux:badge>
                        </div>
                        <flux:icon name="bookmark" class="size-5 shrink-0 {{ $memoColor->iconClasses() }}" />
                    </div>

                    <div x-data="{ expanded: false }">
                        @if (strlen($memo->body) > 220)
                            <flux:text class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">
                                <span x-show="!expanded">{{ Str::limit($memo->body, 220) }}</span>
                                <span x-show="expanded" x-cloak class="whitespace-pre-wrap">{{ $memo->body }}</span>
                            </flux:text>
                            <button
                                type="button"
                                class="mt-1 text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                                x-on:click="expanded = !expanded"
                                x-text="expanded ? @js(__('Show less')) : @js(__('Show more'))"
                            ></button>
                        @else
                            <flux:text class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">{{ $memo->body }}</flux:text>
                        @endif
                    </div>

                    <div class="space-y-1">
                        <flux:text class="text-xs text-zinc-500">
                            {{ $memo->creator->name }}
                            &middot;
                            {{ $memo->created_at->diffForHumans() }}
                        </flux:text>

                        @if ($this->audienceCount > 1)
                            <flux:text class="text-xs text-zinc-400">
                                {{ __(':read of :total staff acknowledged', ['read' => $memo->reads_count, 'total' => $this->audienceCount]) }}
                            </flux:text>
                        @endif
                    </div>

                    <form wire:submit="markAsRead({{ $memo->id }})" class="mt-auto space-y-2 border-t pt-3 {{ $memoColor->dividerClasses() }}">
                        <flux:field>
                            <flux:label class="text-xs">{{ __('Type ":phrase" to dismiss', ['phrase' => ReceptionMemo::READ_CONFIRMATION]) }}</flux:label>
                            <flux:input
                                wire:model="confirmations.{{ $memo->id }}"
                                placeholder="{{ ReceptionMemo::READ_CONFIRMATION }}"
                                size="sm"
                            />
                            <flux:error name="confirmations.{{ $memo->id }}" />
                        </flux:field>

                        <div class="flex gap-2">
                            <flux:button type="submit" size="sm" variant="primary" icon="check" class="flex-1" wire:loading.attr="disabled" wire:target="markAsRead({{ $memo->id }})">
                                {{ __('Confirm Read') }}
                            </flux:button>

                            @if ($memo->canBeDeletedBy(auth()->user()))
                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="deleteMemo({{ $memo->id }})"
                                    wire:confirm="{{ __('Delete this memo for everyone?') }}"
                                />
                            @endif
                        </div>
                    </form>
                </div>
            @empty
                <div class="col-span-full rounded-lg border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center dark:border-zinc-600 dark:bg-zinc-800/50">
                    <flux:icon name="check-circle" class="mx-auto size-10 text-green-500" />
                    <flux:heading level="3" class="mt-3 text-base">{{ __('All caught up') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">{{ __('No unread memos. Post a new note when something needs attention.') }}</flux:text>
                </div>
            @endforelse
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->readMemos as $memo)
                @php($memoColor = $memo->color instanceof ReceptionMemoColor ? $memo->color : ReceptionMemoColor::from($memo->color))
                @php($readReceipt = $memo->reads->first())
                <div
                    wire:key="memo-history-{{ $memo->id }}"
                    class="flex flex-col gap-3 rounded-xl border p-4 opacity-80 {{ $memoColor->cardClasses() }}"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 space-y-1">
                            <flux:heading level="3" class="text-base leading-snug">{{ $memo->title }}</flux:heading>
                            <flux:badge size="sm" class="{{ $memoColor->swatchClasses() }} !text-white">{{ $memoColor->label() }}</flux:badge>
                        </div>
                        <flux:icon name="check-circle" class="size-5 shrink-0 text-green-500" />
                    </div>

                    <flux:text class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">{{ $memo->body }}</flux:text>

                    <div class="mt-auto space-y-1 border-t pt-3 {{ $memoColor->dividerClasses() }}">
                        <flux:text class="text-xs text-zinc-500">
                            {{ $memo->creator->name }}
                            &middot;
                            {{ $memo->created_at->diffForHumans() }}
                        </flux:text>

                        @if ($readReceipt)
                            <flux:text class="text-xs text-zinc-400">
                                {{ __('You acknowledged :time', ['time' => $readReceipt->read_at->diffForHumans()]) }}
                            </flux:text>
                        @endif

                        @if ($memo->canBeDeletedBy(auth()->user()))
                            <div class="pt-2">
                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="deleteMemo({{ $memo->id }})"
                                    wire:confirm="{{ __('Delete this memo for everyone?') }}"
                                >
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-lg border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center dark:border-zinc-600 dark:bg-zinc-800/50">
                    <flux:icon name="clock" class="mx-auto size-10 text-zinc-400" />
                    <flux:heading level="3" class="mt-3 text-base">{{ __('No history yet') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">{{ __('Memos you acknowledge will appear here for reference.') }}</flux:text>
                </div>
            @endforelse
        </div>
    @endif
</div>
