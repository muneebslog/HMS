<?php

use App\Enums\UserRole;
use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public int $userId = 0;

    public bool $open = false;

    public ?int $selectedConversationId = null;

    public string $body = '';

    public string $contactSearch = '';

    /**
     * Prepare the floating chat widget for assigned staff.
     */
    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null || $user->isUser()) {
            return;
        }

        $this->userId = $user->id;
    }

    /**
     * Refresh chat state when a message is broadcast to this user.
     */
    #[On('echo-private:App.Models.User.{userId},.chat.message')]
    public function refreshChat(): void
    {
        if ($this->userId === 0) {
            return;
        }

        unset($this->conversations, $this->chatMessages, $this->selectedConversation, $this->contacts, $this->unreadTotal);

        if ($this->open && $this->selectedConversationId !== null) {
            $this->markSelectedConversationRead();
            unset($this->conversations, $this->unreadTotal);
        }
    }

    /**
     * Toggle the floating chat panel.
     */
    public function toggle(): void
    {
        $this->ensureStaff();

        $this->open = ! $this->open;

        if (! $this->open) {
            return;
        }

        if ($this->selectedConversationId !== null) {
            $this->markSelectedConversationRead();
            unset($this->conversations, $this->unreadTotal);
        }
    }

    /**
     * Close the floating chat panel.
     */
    public function closeChat(): void
    {
        $this->open = false;
    }

    /**
     * Return to the conversation list inside the panel.
     */
    public function showList(): void
    {
        $this->ensureStaff();

        $this->selectedConversationId = null;
        $this->body = '';
        $this->resetValidation();
        unset($this->selectedConversation, $this->chatMessages, $this->conversations, $this->contacts);
    }

    /**
     * Refresh contacts when the search box changes.
     */
    public function updatedContactSearch(): void
    {
        unset($this->contacts, $this->conversations);
    }

    /**
     * Total unread messages across all conversations.
     */
    #[Computed]
    public function unreadTotal(): int
    {
        if ($this->userId === 0) {
            return 0;
        }

        return (int) ChatMessage::query()
            ->whereNull('read_at')
            ->where('user_id', '!=', $this->userId)
            ->whereHas('conversation', fn ($query) => $query->forUser(auth()->user()))
            ->count();
    }

    /**
     * Conversations for the current user, newest activity first.
     *
     * @return Collection<int, ChatConversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $user = auth()->user();
        $search = trim($this->contactSearch);

        return ChatConversation::query()
            ->forUser($user)
            ->with([
                'userOne',
                'userTwo',
                'messages' => fn ($query) => $query->latest()->limit(1),
            ])
            ->withCount([
                'messages as unread_count' => fn ($query) => $query
                    ->where('user_id', '!=', $user->id)
                    ->whereNull('read_at'),
            ])
            ->when($search !== '', function ($query) use ($user, $search): void {
                $query->where(function ($builder) use ($user, $search): void {
                    $builder->where(function ($inner) use ($user, $search): void {
                        $inner->where('user_one_id', $user->id)
                            ->whereHas('userTwo', function ($other) use ($search): void {
                                $other->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('email', 'like', '%'.$search.'%');
                            });
                    })->orWhere(function ($inner) use ($user, $search): void {
                        $inner->where('user_two_id', $user->id)
                            ->whereHas('userOne', function ($other) use ($search): void {
                                $other->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('email', 'like', '%'.$search.'%');
                            });
                    });
                });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Staff contacts available to message.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function contacts(): Collection
    {
        $search = trim($this->contactSearch);

        return User::query()
            ->whereKeyNot(auth()->id())
            ->where('role', '!=', UserRole::User->value)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->limit(40)
            ->get();
    }

    /**
     * The currently selected conversation.
     */
    #[Computed]
    public function selectedConversation(): ?ChatConversation
    {
        if ($this->selectedConversationId === null) {
            return null;
        }

        return ChatConversation::query()
            ->forUser(auth()->user())
            ->with(['userOne', 'userTwo'])
            ->find($this->selectedConversationId);
    }

    /**
     * Messages in the selected conversation.
     *
     * @return Collection<int, ChatMessage>
     */
    #[Computed]
    public function chatMessages(): Collection
    {
        $conversation = $this->selectedConversation;

        if ($conversation === null) {
            return new Collection;
        }

        return $conversation->messages()
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Open an existing conversation.
     */
    public function selectConversation(int $conversationId): void
    {
        $this->ensureStaff();

        $conversation = ChatConversation::query()
            ->forUser(auth()->user())
            ->find($conversationId);

        abort_unless($conversation !== null, 403);

        $this->open = true;
        $this->selectedConversationId = (int) $conversation->id;
        $this->body = '';
        $this->contactSearch = '';
        $this->resetValidation();
        $this->markSelectedConversationRead();

        unset($this->conversations, $this->chatMessages, $this->selectedConversation, $this->unreadTotal, $this->contacts);
    }

    /**
     * Start or open a direct conversation with another staff user.
     */
    public function startConversation(int $userId): void
    {
        $this->ensureStaff();

        $other = User::query()
            ->whereKey($userId)
            ->where('role', '!=', UserRole::User->value)
            ->first();

        abort_unless($other !== null && $other->id !== auth()->id(), 403);

        $conversation = ChatConversation::findOrCreateBetween(auth()->user(), $other);

        $this->selectConversation((int) $conversation->id);
    }

    /**
     * Send a message in the selected conversation.
     */
    public function sendMessage(): void
    {
        $this->ensureStaff();

        $validated = $this->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = auth()->user();
        $conversation = $this->selectedConversation;

        abort_unless($conversation !== null && $conversation->hasParticipant($user), 403);

        $message = DB::transaction(function () use ($validated, $user, $conversation): ChatMessage {
            $message = ChatMessage::query()->create([
                'chat_conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => $validated['body'],
            ]);

            $conversation->update([
                'last_message_at' => now(),
            ]);

            return $message;
        });

        broadcast(new ChatMessageSent($conversation->fresh(), $message, $user))->toOthers();

        $this->body = '';
        unset($this->conversations, $this->chatMessages, $this->selectedConversation, $this->unreadTotal);
    }

    /**
     * Ensure the current user may use staff chat.
     */
    private function ensureStaff(): void
    {
        $user = auth()->user();

        abort_unless($user !== null && ! $user->isUser(), 403);
    }

    /**
     * Mark unread messages in the open conversation as read.
     */
    private function markSelectedConversationRead(): void
    {
        if ($this->selectedConversationId === null || $this->userId === 0) {
            return;
        }

        ChatMessage::query()
            ->where('chat_conversation_id', $this->selectedConversationId)
            ->where('user_id', '!=', $this->userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}; ?>

<div>
    @if ($userId > 0)
        <div class="fixed end-4 bottom-4 z-50 flex flex-col items-end gap-3 sm:end-6 sm:bottom-6">
            @if ($open)
                <div class="flex h-[min(36rem,calc(100vh-7rem))] w-[min(24rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex min-w-0 items-center gap-2">
                            @if ($selectedConversationId)
                                <button type="button" wire:click="showList" class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-700" aria-label="{{ __('Back') }}">
                                    <flux:icon.chevron-left class="size-5" />
                                </button>
                            @endif

                            @if ($selectedConversationId && $this->selectedConversation)
                                @php($other = $this->selectedConversation->otherParticipant(auth()->user()))
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold">{{ $other->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $other->roleLabel() }}</div>
                                </div>
                            @else
                                <flux:heading level="3" class="text-sm">{{ __('Chat') }}</flux:heading>
                            @endif
                        </div>

                        <button type="button" wire:click="closeChat" class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-700" aria-label="{{ __('Close') }}">
                            <flux:icon.x-mark class="size-5" />
                        </button>
                    </div>

                    @if ($selectedConversationId && $this->selectedConversation)
                        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3" wire:key="messages-{{ $selectedConversationId }}">
                            @forelse ($this->chatMessages as $message)
                                @php($mine = (int) $message->user_id === (int) auth()->id())
                                <div wire:key="msg-{{ $message->id }}" class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                                    <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm {{ $mine ? 'rounded-br-md bg-blue-600 text-white' : 'rounded-bl-md bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100' }}">
                                        <div class="whitespace-pre-wrap break-words">{{ $message->body }}</div>
                                        <div class="mt-1 text-[10px] {{ $mine ? 'text-blue-100' : 'text-zinc-500 dark:text-zinc-400' }}">
                                            {{ $message->created_at->format('g:i A') }}
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <flux:text class="block py-8 text-center text-sm text-zinc-500">{{ __('No messages yet. Say hello.') }}</flux:text>
                            @endforelse
                        </div>

                        <form wire:submit="sendMessage" class="flex items-end gap-2 border-t border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="min-w-0 flex-1">
                                <flux:textarea wire:model="body" rows="2" placeholder="{{ __('Type a message...') }}" />
                                <flux:error name="body" />
                            </div>
                            <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" />
                        </form>
                    @else
                        <div class="border-b border-zinc-200 p-3 dark:border-zinc-700">
                            <flux:input
                                wire:model.live.debounce.200ms="contactSearch"
                                placeholder="{{ __('Search staff...') }}"
                                icon="magnifying-glass"
                                autocomplete="off"
                            />
                        </div>

                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-2">
                            <div>
                                <div class="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ __('Staff') }}</div>
                                <div class="space-y-1">
                                    @forelse ($this->contacts as $contact)
                                        <button
                                            type="button"
                                            wire:key="contact-{{ $contact->id }}"
                                            wire:click="startConversation({{ $contact->id }})"
                                            wire:loading.attr="disabled"
                                            class="flex w-full cursor-pointer items-center gap-2 rounded-xl px-2 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                        >
                                            <flux:avatar size="sm">{{ $contact->initials() }}</flux:avatar>
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-medium">{{ $contact->name }}</div>
                                                <div class="truncate text-xs text-zinc-500">{{ $contact->roleLabel() }}</div>
                                            </div>
                                        </button>
                                    @empty
                                        <flux:text class="block px-2 py-3 text-xs text-zinc-500">{{ __('No staff found.') }}</flux:text>
                                    @endforelse
                                </div>
                            </div>

                            @if ($this->conversations->isNotEmpty())
                                <div>
                                    <div class="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ __('Chats') }}</div>
                                    <div class="space-y-1">
                                        @foreach ($this->conversations as $conversation)
                                            @php($other = $conversation->otherParticipant(auth()->user()))
                                            @php($latest = $conversation->messages->first())
                                            <button
                                                type="button"
                                                wire:key="thread-{{ $conversation->id }}"
                                                wire:click="selectConversation({{ $conversation->id }})"
                                                wire:loading.attr="disabled"
                                                class="flex w-full cursor-pointer items-start gap-2 rounded-xl px-2 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                            >
                                                <flux:avatar size="sm">{{ $other->initials() }}</flux:avatar>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="truncate text-sm font-medium">{{ $other->name }}</span>
                                                        @if ($conversation->unread_count > 0)
                                                            <span class="rounded-full bg-blue-600 px-1.5 text-[10px] font-semibold text-white">{{ $conversation->unread_count }}</span>
                                                        @endif
                                                    </div>
                                                    <div class="mt-0.5 truncate text-xs text-zinc-500">
                                                        {{ $latest?->body ?? __('No messages yet') }}
                                                    </div>
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            <button
                type="button"
                wire:click="toggle"
                class="relative flex size-14 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg transition hover:bg-blue-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-900"
                aria-label="{{ __('Chat') }}"
            >
                @if ($open)
                    <flux:icon.x-mark class="size-6" />
                @else
                    <flux:icon.chat-bubble-oval-left-ellipsis class="size-7" />
                @endif

                @if (! $open && $this->unreadTotal > 0)
                    <span class="absolute -top-1 -end-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[11px] font-bold text-white ring-2 ring-white dark:ring-zinc-900">
                        {{ $this->unreadTotal > 99 ? '99+' : $this->unreadTotal }}
                    </span>
                @endif
            </button>
        </div>
    @endif
</div>
