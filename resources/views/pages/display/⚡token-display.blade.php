<?php

use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Services\TokenDisplayService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Token Display')] class extends Component
{
    public ?int $selectedQueueId = null;

    public bool $showQueueSelector = true;

    public ?string $pin = '';

    public bool $pinVerified = false;

    /**
     * Initialize the component state.
     */
    public function mount(): void
    {
        $this->pinVerified = (bool) session('display_pin_verified', false);
    }

    /**
     * Open non-file-check queues for today.
     *
     * @return Collection<int, ServiceQueue>
     */
    #[Computed]
    public function queues(): Collection
    {
        return app(TokenDisplayService::class)->primaryQueues();
    }

    /**
     * Get the currently selected queue.
     */
    #[Computed]
    public function selectedQueue(): ?ServiceQueue
    {
        if ($this->selectedQueueId === null) {
            return null;
        }

        return ServiceQueue::with([
            'service',
            'doctor',
            'tokens.patient',
            'tokens.invoiceItem.invoice.patient',
        ])->find($this->selectedQueueId);
    }

    /**
     * Arrived waiting tokens for the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function waitingTokens(): Collection
    {
        if ($this->selectedQueue === null) {
            return new Collection;
        }

        return app(TokenDisplayService::class)->waitingTokens($this->selectedQueue);
    }

    /**
     * Serving tokens for the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function servingTokens(): Collection
    {
        if ($this->selectedQueue === null) {
            return new Collection;
        }

        return app(TokenDisplayService::class)->servingTokens($this->selectedQueue);
    }

    /**
     * File-check waiting tokens for today.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function fileCheckWaitingTokens(): Collection
    {
        return app(TokenDisplayService::class)->fileCheckWaitingTokens();
    }

    /**
     * File-check serving tokens for today.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function fileCheckServingTokens(): Collection
    {
        return app(TokenDisplayService::class)->fileCheckServingTokens();
    }

    /**
     * Select a queue and start displaying its tokens.
     */
    public function selectQueue(int $id): void
    {
        $queue = ServiceQueue::findOrFail($id);

        abort_if(app(TokenDisplayService::class)->isFileCheckQueue($queue), 404);

        $this->selectedQueueId = $id;
        $this->showQueueSelector = false;
    }

    /**
     * Show the queue selector again.
     */
    public function showQueues(): void
    {
        $this->selectedQueueId = null;
        $this->showQueueSelector = true;
    }

    /**
     * Verify the display PIN and unlock the controls.
     */
    public function verifyPin(): void
    {
        if ($this->pin !== config('display.pin')) {
            $this->addError('pin', __('Invalid PIN.'));

            return;
        }

        session(['display_pin_verified' => true]);
        $this->pinVerified = true;
        $this->pin = '';
        $this->resetErrorBag();
    }

    /**
     * Lock the controls by clearing the verified PIN session.
     */
    public function lock(): void
    {
        session()->forget('display_pin_verified');
        $this->pinVerified = false;
    }

    /**
     * Move a waiting token to now serving.
     */
    public function startServing(int $tokenId): void
    {
        $this->ensurePinVerified();

        $token = QueueToken::findOrFail($tokenId);
        $this->ensureTokenOnBoard($token);

        app(TokenDisplayService::class)->startServing($token);
    }

    /**
     * Mark a serving token as served.
     */
    public function markServed(int $tokenId): void
    {
        $this->ensurePinVerified();

        $token = QueueToken::findOrFail($tokenId);
        $this->ensureTokenOnBoard($token);

        app(TokenDisplayService::class)->markServed($token);
    }

    /**
     * Ensure the PIN has been verified before performing a control action.
     */
    private function ensurePinVerified(): void
    {
        abort_if(! $this->pinVerified, 403);
    }

    /**
     * Ensure the token belongs to the selected queue or a file-check queue.
     */
    private function ensureTokenOnBoard(QueueToken $token): void
    {
        $display = app(TokenDisplayService::class);

        $allowedQueueIds = collect([$this->selectedQueueId])
            ->merge($display->fileCheckQueues()->modelKeys())
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        abort_unless(in_array((int) $token->service_queue_id, $allowedQueueIds, true), 404);
    }
}; ?>

<div class="flex min-h-screen flex-col" wire:poll.5s>
    {{-- Top bar --}}
    <div class="flex h-16 shrink-0 items-center justify-between border-b border-zinc-800 bg-zinc-900 px-4 sm:px-6">
        <div class="flex items-center gap-3 sm:gap-4">
            <h1 class="text-lg font-bold text-white sm:text-xl">
                {{ config('app.name', 'HMS') }}
            </h1>

            @if ($this->selectedQueue)
                <flux:badge variant="success" size="sm">
                    {{ $this->selectedQueue->service->name }}
                </flux:badge>

                @if ($this->selectedQueue->doctor)
                    <p class="hidden text-base text-zinc-400 sm:block">
                        {{ $this->selectedQueue->doctor->name }}
                    </p>
                @endif
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($this->selectedQueue)
                @if ($pinVerified)
                    <flux:button
                        type="button"
                        variant="ghost"
                        icon="lock-closed"
                        wire:click="lock"
                        class="hidden sm:inline-flex"
                    >
                        {{ __('Lock') }}
                    </flux:button>
                @endif

                <flux:button
                    type="button"
                    variant="ghost"
                    icon="arrow-left-start-on-rectangle"
                    wire:click="showQueues"
                    class="hidden sm:inline-flex"
                >
                    {{ __('Switch Queue') }}
                </flux:button>

                <flux:button
                    type="button"
                    variant="ghost"
                    icon="arrow-left-start-on-rectangle"
                    wire:click="showQueues"
                    class="sm:hidden"
                    title="{{ __('Switch Queue') }}"
                />
            @endif
        </div>
    </div>

    {{-- Queue selector --}}
    @if ($this->showQueueSelector || $this->selectedQueue === null)
        <div class="flex flex-1 flex-col items-center justify-center p-6">
            <flux:heading level="2" size="xl" class="mb-8 text-center">
                {{ __('Select a Queue') }}
            </flux:heading>

            @if ($this->queues->isEmpty())
                <flux:text class="text-zinc-500">
                    {{ __('No open queues available.') }}
                </flux:text>
            @else
                <div class="grid w-full max-w-5xl grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->queues as $queue)
                        <flux:button
                            type="button"
                            wire:click="selectQueue({{ $queue->id }})"
                            wire:key="queue-card-{{ $queue->id }}"
                            variant="filled"
                            class="h-auto flex-col items-start justify-start gap-1 p-6 text-left"
                        >
                            <span class="text-lg font-bold text-white">{{ $queue->service->name }}</span>
                            <span class="text-zinc-400">{{ $queue->doctor?->name ?? __('No doctor assigned') }}</span>
                        </flux:button>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Waiting room board --}}
        <div class="flex flex-1 flex-col overflow-hidden">
            {{-- Patients Waiting --}}
            <section class="flex min-h-0 flex-1 flex-col border-b border-zinc-800 p-4 sm:p-6">
                <div class="mb-4 shrink-0">
                    <h2 class="text-2xl font-bold text-white sm:text-3xl">{{ __('Patients waiting') }}</h2>
                    <p class="mt-1 text-sm text-zinc-400 sm:text-base">{{ __('(Arrived)') }}</p>
                </div>

                <div class="flex min-h-0 flex-1 gap-4">
                    <aside class="flex w-20 shrink-0 flex-col gap-3 overflow-y-auto border-r border-zinc-800 pr-4 sm:w-28 sm:gap-4">
                        @forelse ($this->fileCheckWaitingTokens as $token)
                            <button
                                type="button"
                                wire:key="file-wait-{{ $token->id }}"
                                wire:click="startServing({{ $token->id }})"
                                class="flex aspect-square items-center justify-center rounded-2xl border-2 border-amber-500/60 bg-amber-950/40 text-2xl font-black text-amber-200 transition hover:bg-amber-900/50 sm:text-3xl"
                            >
                                {{ $token->token_number }}
                            </button>
                        @empty
                            <p class="text-center text-xs text-zinc-600">—</p>
                        @endforelse
                    </aside>

                    <div class="flex flex-1 flex-wrap content-start gap-3 overflow-y-auto sm:gap-4">
                        @forelse ($this->waitingTokens as $token)
                            <button
                                type="button"
                                wire:key="wait-{{ $token->id }}"
                                wire:click="startServing({{ $token->id }})"
                                class="flex h-20 w-20 items-center justify-center rounded-2xl border-2 border-zinc-600 bg-zinc-800 text-3xl font-black text-white transition hover:border-sky-400 hover:bg-zinc-700 sm:h-24 sm:w-24 sm:text-4xl"
                            >
                                {{ $token->token_number }}
                            </button>
                        @empty
                            <p class="text-lg text-zinc-500">{{ __('No patients waiting.') }}</p>
                        @endforelse
                    </div>
                </div>
            </section>

            {{-- Now Serving --}}
            <section class="flex min-h-0 flex-1 flex-col p-4 sm:p-6">
                <div class="mb-4 shrink-0 text-center sm:text-left">
                    <h2 class="text-2xl font-bold text-white sm:text-3xl">{{ __('Now Serving') }}</h2>
                    <p class="mt-1 text-lg text-zinc-300" dir="rtl">اب باری ہے</p>
                </div>

                <div class="flex min-h-0 flex-1 gap-4">
                    <div class="flex flex-1 flex-wrap content-start gap-3 overflow-y-auto sm:gap-4">
                        @forelse ($this->servingTokens as $token)
                            <button
                                type="button"
                                wire:key="serve-{{ $token->id }}"
                                wire:click="markServed({{ $token->id }})"
                                class="flex h-24 w-24 items-center justify-center rounded-2xl border-2 border-emerald-500 bg-emerald-950/50 text-4xl font-black text-emerald-300 transition hover:bg-emerald-900/60 sm:h-28 sm:w-28 sm:text-5xl"
                            >
                                {{ $token->token_number }}
                            </button>
                        @empty
                            <p class="text-lg text-zinc-500">{{ __('No token being served') }}</p>
                        @endforelse
                    </div>

                    <aside class="flex w-36 shrink-0 flex-col gap-3 overflow-y-auto border-l border-zinc-800 pl-4 sm:w-44">
                        <p class="text-sm font-semibold uppercase tracking-wide text-zinc-400">
                            {{ __('File check for patients') }}
                        </p>

                        @forelse ($this->fileCheckServingTokens as $token)
                            <button
                                type="button"
                                wire:key="file-serve-{{ $token->id }}"
                                wire:click="markServed({{ $token->id }})"
                                class="flex h-16 items-center justify-center rounded-2xl border-2 border-amber-500/60 bg-amber-950/40 text-2xl font-black text-amber-200 transition hover:bg-amber-900/50 sm:h-20 sm:text-3xl"
                            >
                                {{ $token->token_number }}
                            </button>
                        @empty
                            <p class="text-sm text-zinc-600">—</p>
                        @endforelse
                    </aside>
                </div>
            </section>
        </div>

        {{-- PIN prompt --}}
        @if (! $pinVerified)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/95 p-4">
                <div class="w-full max-w-sm rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-xl">
                    <flux:heading level="2" size="lg" class="text-center">
                        {{ __('Enter PIN') }}
                    </flux:heading>

                    <flux:text class="mt-2 text-center text-zinc-500">
                        {{ __('Enter the 4-digit PIN to unlock the controls.') }}
                    </flux:text>

                    <form wire:submit="verifyPin" class="mt-6 space-y-4">
                        <flux:input
                            type="password"
                            wire:model="pin"
                            inputmode="numeric"
                            pattern="[0-9]{4}"
                            maxlength="4"
                            placeholder="----"
                            class="text-center text-2xl tracking-[0.5em]"
                            autofocus
                        />

                        @error('pin')
                            <flux:text variant="danger" class="text-center">{{ $message }}</flux:text>
                        @enderror

                        <flux:button type="submit" variant="primary" class="w-full">
                            {{ __('Unlock') }}
                        </flux:button>
                    </form>
                </div>
            </div>
        @endif
    @endif
</div>
