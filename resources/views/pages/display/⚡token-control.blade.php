<?php

use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Services\TokenDisplayService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Token Control')] class extends Component
{
    #[Url]
    public ?int $selectedQueueId = null;

    public bool $showQueueSelector = true;

    /**
     * Initialize the component state.
     */
    public function mount(): void
    {
        abort_if(! auth()->check(), 403);

        $this->showQueueSelector = $this->selectedQueueId === null;
    }

    /**
     * Get all open service queues for today.
     *
     * @return Collection<int, ServiceQueue>
     */
    #[Computed]
    public function queues(): Collection
    {
        return ServiceQueue::with(['service', 'doctor'])
            ->where('status', 'open')
            ->whereDate('date', Carbon::today())
            ->orderBy('opened_at')
            ->get();
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
     * Get the token currently being served.
     */
    #[Computed]
    public function currentToken(): ?QueueToken
    {
        if ($this->selectedQueue === null) {
            return null;
        }

        return app(TokenDisplayService::class)->currentToken($this->selectedQueue);
    }

    /**
     * Get the upcoming tokens for the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function upcomingTokens(): Collection
    {
        if ($this->selectedQueueId === null) {
            return new Collection();
        }

        return QueueToken::with(['patient', 'invoiceItem.invoice.patient'])
            ->where('service_queue_id', $this->selectedQueueId)
            ->whereIn('status', ['reserved', 'waiting'])
            ->orderBy('token_number')
            ->limit(16)
            ->get();
    }

    /**
     * Select a queue and start controlling its tokens.
     */
    public function selectQueue(int $id): void
    {
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
     * Call the next waiting token.
     */
    public function callNext(): void
    {
        if ($this->selectedQueue === null) {
            return;
        }

        app(TokenDisplayService::class)->callNext($this->selectedQueue);
    }

    /**
     * Call the previous token.
     */
    public function callPrevious(): void
    {
        if ($this->selectedQueue === null) {
            return;
        }

        app(TokenDisplayService::class)->callPrevious($this->selectedQueue);
    }

    /**
     * Skip the currently serving token and call the next one.
     */
    public function skipCurrent(): void
    {
        if ($this->selectedQueue === null) {
            return;
        }

        app(TokenDisplayService::class)->skipCurrent($this->selectedQueue);
    }

    /**
     * Recall the currently serving token.
     */
    public function recallCurrent(): void
    {
        // Recall is intentionally a no-op on the data model; it simply
        // refreshes the display so the current token can be re-announced.
    }
}; ?>

<div class="flex min-h-screen flex-col" wire:poll.5s>
    {{-- Top bar --}}
    <div class="flex h-16 shrink-0 items-center justify-between border-b border-zinc-800 bg-zinc-900 px-6">
        <div class="flex items-center gap-4">
            <h1 class="text-xl font-bold text-white">
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
            @else
                <span class="text-zinc-400">{{ __('Token Control') }}</span>
            @endif
        </div>

        @if ($this->selectedQueue)
            <flux:button
                type="button"
                variant="ghost"
                icon="arrow-left-start-on-rectangle"
                wire:click="showQueues"
            >
                {{ __('Switch Queue') }}
            </flux:button>
        @endif
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
        {{-- Control panel --}}
        <div class="flex flex-1 flex-col gap-6 p-6">
            <flux:card class="flex flex-1 flex-col items-center justify-center gap-6 text-center">
                @if ($this->currentToken)
                    <flux:text class="text-sm font-medium uppercase tracking-widest text-zinc-500">
                        {{ __('Now Serving') }}
                    </flux:text>

                    <div class="text-8xl font-black text-white sm:text-9xl">
                        {{ $this->currentToken->token_number }}
                    </div>

                    <div class="text-2xl font-semibold text-white sm:text-4xl">
                        {{ $this->currentToken->patient?->name ?? $this->currentToken->invoiceItem?->invoice?->patient?->name ?? '-' }}
                    </div>

                    @if ($this->selectedQueue?->doctor)
                        <div class="text-lg text-zinc-400 sm:text-xl">
                            {{ $this->selectedQueue->doctor->name }}
                        </div>
                    @endif
                @else
                    <flux:heading level="2" size="xl" class="text-zinc-300">
                        {{ __('No token being served') }}
                    </flux:heading>

                    <flux:text class="text-zinc-500">
                        {{ __('Use the controls below to call the next token.') }}
                    </flux:text>
                @endif
            </flux:card>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <flux:button
                    type="button"
                    wire:click="callPrevious"
                    icon="arrow-left"
                    variant="primary"
                    :disabled="! $this->currentToken"
                    class="justify-center py-6 text-lg"
                >
                    {{ __('Back') }}
                </flux:button>

                <flux:button
                    type="button"
                    wire:click="recallCurrent"
                    icon="speaker-wave"
                    variant="primary"
                    :disabled="! $this->currentToken"
                    class="justify-center py-6 text-lg"
                >
                    {{ __('Recall') }}
                </flux:button>

                <flux:button
                    type="button"
                    wire:click="skipCurrent"
                    icon="forward"
                    variant="danger"
                    :disabled="! $this->currentToken"
                    class="justify-center py-6 text-lg"
                >
                    {{ __('Skip') }}
                </flux:button>

                <flux:button
                    type="button"
                    wire:click="callNext"
                    icon="arrow-right"
                    variant="primary"
                    class="col-span-2 justify-center py-6 text-lg"
                >
                    {{ __('Next Token') }}
                </flux:button>
            </div>

            <flux:card>
                <flux:heading level="3" size="lg" class="mb-4">
                    {{ __('Upcoming') }}
                </flux:heading>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($this->upcomingTokens as $token)
                        <div
                            class="flex items-center justify-between rounded-xl border border-zinc-800 bg-zinc-900 p-3"
                            wire:key="control-upcoming-token-{{ $token->id }}"
                        >
                            <div>
                                <div class="text-xl font-bold text-white">
                                    {{ $token->token_number }}
                                </div>
                                <div class="text-sm text-zinc-400">
                                    {{ $token->patient?->name ?? $token->invoiceItem?->invoice?->patient?->name ?? '-' }}
                                </div>
                                @if ($token->status === 'reserved')
                                    <flux:badge variant="danger" size="sm" class="mt-2">{{ __('Not Arrived') }}</flux:badge>
                                @else
                                    <flux:badge variant="success" size="sm" class="mt-2">{{ __('Arrived') }}</flux:badge>
                                @endif
                            </div>
                        </div>
                    @empty
                        <flux:text class="col-span-full text-zinc-500">
                            {{ __('No upcoming tokens.') }}
                        </flux:text>
                    @endforelse
                </div>
            </flux:card>
        </div>
    @endif
</div>
