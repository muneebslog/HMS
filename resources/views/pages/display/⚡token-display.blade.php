<?php

use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Services\TokenDisplayService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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
     * Select a queue and start displaying its tokens.
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
     * Call the next waiting token.
     */
    public function callNext(): void
    {
        $this->ensurePinVerified();

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
        $this->ensurePinVerified();

        if ($this->selectedQueue === null) {
            return;
        }

        app(TokenDisplayService::class)->callPrevious($this->selectedQueue);
    }


    /**
     * Ensure the PIN has been verified before performing a control action.
     */
    private function ensurePinVerified(): void
    {
        abort_if(! $this->pinVerified, 403);
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
        {{-- Token display --}}
        <div class="flex flex-1 flex-col items-center justify-center p-6 pb-24 text-center">
            @if ($this->currentToken)
                <flux:text class="mb-4 text-xs font-medium uppercase tracking-widest text-zinc-500 sm:text-sm">
                    {{ __('Now Serving') }}
                </flux:text>

                <div class="text-7xl font-black text-white sm:text-8xl md:text-9xl lg:text-[180px]">
                    {{ $this->currentToken->token_number }}
                </div>

                <div class="mt-4 text-2xl font-semibold text-white sm:text-3xl md:text-4xl lg:mt-6">
                    {{ $this->currentToken->patient?->name ?? $this->currentToken->invoiceItem?->invoice?->patient?->name ?? '-' }}
                </div>

                @if ($this->selectedQueue?->doctor)
                    <div class="mt-2 text-lg text-zinc-400 lg:mt-3 lg:text-2xl">
                        {{ $this->selectedQueue->doctor->name }}
                    </div>
                @endif

                @if ($this->currentToken->status === 'reserved')
                    <flux:badge variant="danger" size="lg" class="mt-4">{{ __('Not Arrived') }}</flux:badge>
                @else
                    <flux:badge variant="success" size="lg" class="mt-4">{{ __('Arrived') }}</flux:badge>
                @endif
            @else
                <flux:heading level="2" size="xl" class="text-zinc-300">
                    {{ __('No token being served') }}
                </flux:heading>

                <flux:text class="mt-4 text-zinc-500">
                    {{ __('Use the controls to call the next token.') }}
                </flux:text>
            @endif
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

        {{-- Controls --}}
        @if ($pinVerified)
            <div class="fixed bottom-0 left-0 right-0 z-10 flex flex-wrap items-center justify-end gap-2 border-t border-zinc-800 bg-zinc-900/95 p-3 backdrop-blur sm:gap-3 lg:absolute lg:right-6 lg:bottom-6 lg:left-auto lg:w-auto lg:border-0 lg:bg-transparent lg:p-0">
                <flux:button
                    type="button"
                    wire:click="callPrevious"
                    icon="arrow-left"
                    variant="primary"
                    :disabled="! $this->currentToken"
                >
                    {{ __('Back') }}
                </flux:button>

                <flux:button
                    type="button"
                    wire:click="callNext"
                    icon="arrow-right"
                    variant="primary"
                >
                    {{ __('Next') }}
                </flux:button>
            </div>
        @endif
    @endif
</div>
