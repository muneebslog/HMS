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
     * Get the upcoming waiting tokens for the selected queue.
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
            ->where('status', 'waiting')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(8)
            ->get();
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
     * Call the next waiting token.
     */
    public function callNext(): void
    {
        $this->ensureAuthenticated();

        if ($this->selectedQueue === null) {
            return;
        }

        app(TokenDisplayService::class)->callNext($this->selectedQueue);
    }

    /**
     * Skip the currently serving token and call the next one.
     */
    public function skipCurrent(): void
    {
        $this->ensureAuthenticated();

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
        $this->ensureAuthenticated();
    }

    /**
     * Ensure the user is authenticated before performing a control action.
     */
    private function ensureAuthenticated(): void
    {
        abort_if(! auth()->check(), 403);
    }
}; ?>

<div
    style="display: flex; flex-direction: column; height: 100vh; width: 100%; overflow: hidden;"
    wire:poll.5s
>
    {{-- Top bar --}}
    <div style="display: flex; align-items: center; justify-content: space-between; height: 64px; padding: 0 24px; background-color: #18181b; border-bottom: 1px solid #27272a;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <h1 style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">
                {{ config('app.name', 'HMS') }}
            </h1>

            @if ($this->selectedQueue)
                <span style="display: inline-flex; align-items: center; padding: 6px 12px; font-size: 14px; font-weight: 500; color: #14532d; background-color: #86efac; border-radius: 6px;">
                    {{ $this->selectedQueue->service->name }}
                </span>

                @if ($this->selectedQueue->doctor)
                    <p style="margin: 0; font-size: 16px; color: #a1a1aa;">
                        {{ $this->selectedQueue->doctor->name }}
                    </p>
                @endif
            @endif
        </div>

        @if ($this->selectedQueue)
            <button
                type="button"
                wire:click="showQueues"
                style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; font-size: 14px; color: #ffffff; background-color: transparent; border: 1px solid #3f3f46; border-radius: 8px;"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                    <path fill-rule="evenodd" d="M14 8a.75.75 0 0 1-.75.75H4.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 0 1 1.06 1.06L4.56 7.25h8.69A.75.75 0 0 1 14 8Z" clip-rule="evenodd"/>
                </svg>
                {{ __('Switch Queue') }}
            </button>
        @endif
    </div>

    {{-- Queue selector --}}
    @if ($this->showQueueSelector || $this->selectedQueue === null)
        <div style="display: flex; flex: 1; flex-direction: column; align-items: center; justify-content: center; gap: 32px; padding: 32px;">
            <h2 style="margin: 0; font-size: 32px; font-weight: 600; color: #ffffff;">
                {{ __('Select a Queue') }}
            </h2>

            @if ($this->queues->isEmpty())
                <p style="margin: 0; font-size: 20px; color: #a1a1aa;">
                    {{ __('No open queues available.') }}
                </p>
            @else
                <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 24px; width: 100%; max-width: 1200px;">
                    @foreach ($this->queues as $queue)
                        <button
                            type="button"
                            wire:click="selectQueue({{ $queue->id }})"
                            wire:key="queue-card-{{ $queue->id }}"
                            style="display: flex; flex-direction: column; align-items: flex-start; gap: 8px; width: 320px; padding: 24px; text-align: left; background-color: #18181b; border: 1px solid #3f3f46; border-radius: 16px;"
                        >
                            <h3 style="margin: 0; font-size: 24px; font-weight: 700; color: #ffffff;">
                                {{ $queue->service->name }}
                            </h3>

                            <p style="margin: 0; font-size: 18px; color: #a1a1aa;">
                                {{ $queue->doctor?->name ?? __('No doctor assigned') }}
                            </p>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Token display --}}
        <div style="display: flex; flex: 1; position: relative; overflow: hidden;">
            <div style="display: flex; flex: 1; flex-direction: column; align-items: center; justify-content: center; padding: 32px;">
                @if ($this->currentToken)
                    <div style="text-align: center; color: #ffffff;">
                        <p style="margin: 0 0 16px 0; font-size: 20px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em; color: #a1a1aa;">
                            {{ __('Now Serving') }}
                        </p>

                        <div style="font-size: 180px; font-weight: 900; line-height: 1;">
                            {{ $this->currentToken->token_number }}
                        </div>

                        <div style="margin-top: 24px; font-size: 48px; font-weight: 600;">
                            {{ $this->currentToken->patient?->name ?? $this->currentToken->invoiceItem?->invoice?->patient?->name ?? '-' }}
                        </div>

                        @if ($this->selectedQueue?->doctor)
                            <div style="margin-top: 12px; font-size: 24px; color: #a1a1aa;">
                                {{ $this->selectedQueue->doctor->name }}
                            </div>
                        @endif
                    </div>
                @else
                    <div style="text-align: center;">
                        <p style="margin: 0; font-size: 48px; font-weight: 600; color: #d4d4d8;">
                            {{ __('No token being served') }}
                        </p>

                        <p style="margin: 16px 0 0 0; font-size: 24px; color: #71717a;">
                            {{ __('Use the controls to call the next token.') }}
                        </p>
                    </div>
                @endif
            </div>

            {{-- Upcoming tokens sidebar --}}
            <div style="display: flex; flex-direction: column; width: 320px; padding: 24px; background-color: rgba(24, 24, 27, 0.5); border-left: 1px solid #27272a;">
                <h3 style="margin: 0 0 24px 0; font-size: 20px; font-weight: 600; color: #ffffff;">
                    {{ __('Upcoming') }}
                </h3>

                <div style="display: flex; flex: 1; flex-direction: column; gap: 12px; overflow-y: auto;">
                    @forelse ($this->upcomingTokens as $token)
                        <div
                            style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background-color: #18181b; border: 1px solid #27272a; border-radius: 12px;"
                            wire:key="upcoming-token-{{ $token->id }}"
                        >
                            <div>
                                <div style="font-size: 24px; font-weight: 700; color: #ffffff;">
                                    {{ $token->token_number }}
                                </div>
                                <div style="font-size: 14px; color: #a1a1aa;">
                                    {{ $token->patient?->name ?? $token->invoiceItem?->invoice?->patient?->name ?? '-' }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <p style="margin: 0; font-size: 16px; color: #71717a;">
                            {{ __('No waiting tokens.') }}
                        </p>
                    @endforelse
                </div>
            </div>

            {{-- Controls --}}
            @auth
                <div style="position: absolute; right: 24px; bottom: 24px; display: flex; gap: 12px;">
                    <button
                        type="button"
                        wire:click="recallCurrent"
                        @disabled(! $this->currentToken)
                        style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #2563eb; border: none; border-radius: 8px; opacity: {{ $this->currentToken ? '1' : '0.5' }};"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M8.5 1.5a.5.5 0 0 0-1 0v3.879L5.479 3.358a.5.5 0 1 0-.707.707l2.828 2.828a.5.5 0 0 0 .707 0l2.828-2.828a.5.5 0 1 0-.707-.707L8.5 5.379V1.5Z"/>
                            <path d="M12.5 9a.5.5 0 0 1-.5.5H8.5v2.5a.5.5 0 0 1-1 0V9.5H5a.5.5 0 0 1 0-1h8a.5.5 0 0 1 .5.5Z"/>
                        </svg>
                        {{ __('Recall') }}
                    </button>

                    <button
                        type="button"
                        wire:click="skipCurrent"
                        @disabled(! $this->currentToken)
                        style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #dc2626; border: none; border-radius: 8px; opacity: {{ $this->currentToken ? '1' : '0.5' }};"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                            <path fill-rule="evenodd" d="M2 8a.75.75 0 0 1 .75-.75h8.69l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 1 1-1.06-1.06l3.22-3.22H2.75A.75.75 0 0 1 2 8Z" clip-rule="evenodd"/>
                        </svg>
                        {{ __('Skip') }}
                    </button>

                    <button
                        type="button"
                        wire:click="callNext"
                        style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; font-size: 16px; font-weight: 500; color: #ffffff; background-color: #2563eb; border: none; border-radius: 8px;"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                            <path fill-rule="evenodd" d="M2 8a.75.75 0 0 1 .75-.75h8.69l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 1 1-1.06-1.06l3.22-3.22H2.75A.75.75 0 0 1 2 8Z" clip-rule="evenodd"/>
                        </svg>
                        {{ __('Next') }}
                    </button>
                </div>
            @endauth
        </div>
    @endif
</div>
