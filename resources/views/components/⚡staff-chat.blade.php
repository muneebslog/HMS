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

        if ($this->open && $this->selectedConversationId !== null) {
            $this->markSelectedConversationRead();
            unset($this->conversations, $this->unreadTotal);
        }
    }

    /**
     * Close the floating chat panel.
     */
    public function close(): void
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
        unset($this->selectedConversation, $this->chatMessages);
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
            ->where('id', '!=', auth()->id())
            ->where('role', '!=', UserRole::User)
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
        if ($this->selectedConversationId === null) {
            return new Collection;
        }

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
        $this->selectedConversationId = $conversation->id;
        $this->body = '';
        $this->resetValidation();
        $this->markSelectedConversationRead();

        unset($this->conversations, $this->chatMessages, $this->selectedConversation, $this->unreadTotal);
    }

    /**
     * Start or open a direct conversation with another staff user.
     */
    public function startConversation(int $userId): void
    {
        $this->ensureStaff();

        $other = User::query()
            ->where('id', $userId)
            ->where('role', '!=', UserRole::User)
            ->first();

        abort_unless($other !== null && $other->id !== auth()->id(), 403);

        $conversation = ChatConversation::findOrCreateBetween(auth()->user(), $other);

        $this->contactSearch = '';
        $this->selectConversation($conversation->id);
        unset($this->contacts);
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

@if ($userId > 0)
    <div
        wire:ignore.self
        class="pointer-events-none fixed end-4 bottom-4 z-50 flex flex-col items-end gap-3 sm:end-6 sm:bottom-6"
        x-data="{
            online: {},
            init() {
                if (! window.Echo) {
                    return;
                }

                window.Echo.join('hms.staff')
                    .here((users) => {
                        this.online = Object.fromEntries(users.map((user) => [user.id, user]));
                    })
                    .joining((user) => {
                        this.online[user.id] = user;
                    })
                    .leaving((user) => {
                        delete this.online[user.id];
                    });
            },
            isOnline(id) {
                return Boolean(this.online[id]);
            },
        }"
    >
        @if ($open)
            <div class="pointer-events-auto flex h-[min(36rem,calc(100vh-7rem))] w-[min(24rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex min-w-0 items-center gap-2">
                        @if ($this->selectedConversation)
                            <button
                                type="button"
                                wire:click="showList"
                                class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-700"
                                aria-label="{{ __('Back') }}"
                            >
                                <flux:icon.chevron-left class="size-5" />
                            </button>
                            @php($other = $this->selectedConversation->otherParticipant(auth()->user()))
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold">{{ $other->name }}</span>
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :class="isOnline({{ $other->id }}) ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'"
                                    ></span>
                                </div>
                                <div class="truncate text-xs text-zinc-500">
                                    <span x-text="isOnline({{ $other->id }}) ? @js(__('Active now')) : @js(__('Offline'))"></span>
                                    &middot;
                                    {{ $other->roleLabel() }}
                                </div>
                            </div>
                        @else
                            <flux:heading level="3" class="text-sm">{{ __('Chat') }}</flux:heading>
                        @endif
                    </div>

                    <button
                        type="button"
                        wire:click="close"
                        class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-700"
                        aria-label="{{ __('Close') }}"
                    >
                        <flux:icon.x-mark class="size-5" />
                    </button>
                </div>

                @if ($this->selectedConversation)
                    <div
                        class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3"
                        x-data
                        x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        wire:key="chat-thread-scroll-{{ $selectedConversationId }}-{{ $this->chatMessages->count() }}"
                    >
                        @foreach ($this->chatMessages as $message)
                            @php($mine = $message->user_id === auth()->id())
                            <div wire:key="chat-message-{{ $message->id }}" class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm {{ $mine ? 'rounded-br-md bg-blue-600 text-white' : 'rounded-bl-md bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100' }}">
                                    <div class="whitespace-pre-wrap break-words">{{ $message->body }}</div>
                                    <div class="mt-1 text-[10px] {{ $mine ? 'text-blue-100' : 'text-zinc-500 dark:text-zinc-400' }}">
                                        {{ $message->created_at->format('g:i A') }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <form wire:submit="sendMessage" class="flex items-end gap-2 border-t border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="min-w-0 flex-1">
                            <flux:textarea
                                wire:model="body"
                                rows="2"
                                placeholder="{{ __('Type a message...') }}"
                            />
                            <flux:error name="body" />
                        </div>
                        <flux:button type="submit" variant="primary" icon="paper-airplane" />
                    </form>
                @else
                    <div class="border-b border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:input
                            wire:model.live.debounce.300ms="contactSearch"
                            placeholder="{{ __('Search staff...') }}"
                            icon="magnifying-glass"
                        />

                        @if (trim($contactSearch) !== '')
                            <div class="mt-2 max-h-28 space-y-1 overflow-y-auto">
                                @forelse ($this->contacts as $contact)
                                    <button
                                        type="button"
                                        wire:key="chat-contact-{{ $contact->id }}"
                                        wire:click="startConversation({{ $contact->id }})"
                                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                    >
                                        <div class="relative shrink-0">
                                            <flux:avatar size="xs">{{ $contact->initials() }}</flux:avatar>
                                            <span
                                                class="absolute -end-0.5 -bottom-0.5 size-2 rounded-full ring-2 ring-white dark:ring-zinc-900"
                                                :class="isOnline({{ $contact->id }}) ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'"
                                            ></span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-medium">{{ $contact->name }}</div>
                                            <div class="truncate text-xs text-zinc-500">{{ $contact->roleLabel() }}</div>
                                        </div>
                                    </button>
                                @empty
                                    <flux:text class="px-2 py-2 text-xs text-zinc-500">{{ __('No staff found.') }}</flux:text>
                                @endforelse
                            </div>
                        @endif
                    </div>

                    <div class="min-h-0 flex-1 space-y-1 overflow-y-auto p-2">
                        @forelse ($this->conversations as $conversation)
                            @php($other = $conversation->otherParticipant(auth()->user()))
                            @php($latest = $conversation->messages->first())
                            <button
                                type="button"
                                wire:key="chat-thread-{{ $conversation->id }}"
                                wire:click="selectConversation({{ $conversation->id }})"
                                class="flex w-full items-start gap-2 rounded-xl px-2 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800"
                            >
                                <div class="relative shrink-0">
                                    <flux:avatar size="sm">{{ $other->initials() }}</flux:avatar>
                                    <span
                                        class="absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-zinc-900"
                                        :class="isOnline({{ $other->id }}) ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'"
                                    ></span>
                                </div>
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
                        @empty
                            <div class="px-3 py-10 text-center">
                                <flux:text class="text-sm text-zinc-500">{{ __('Search staff above to start chatting.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
        @endif

        <button
            type="button"
            wire:click="toggle"
            class="pointer-events-auto relative flex size-14 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg transition hover:bg-blue-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-900"
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
