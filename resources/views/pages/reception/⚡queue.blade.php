<?php

use App\Enums\TokenResetType;
use App\Models\ServiceQueue;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Queue')] class extends Component
{
    public ?int $viewingQueueId = null;

    public bool $showTokensModal = false;

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function currentShift(): ?Shift
    {
        return Shift::currentForUser(auth()->id());
    }

    /**
     * Get the open service queues available for the current shift.
     *
     * @return Collection<int, ServiceQueue>
     */
    #[Computed]
    public function queues(): Collection
    {
        $currentShift = $this->currentShift;

        if ($currentShift === null) {
            return new Collection();
        }

        $shiftDate = $currentShift->opened_at->toDateString();

        return ServiceQueue::with(['service', 'doctor'])
            ->withCount('tokens')
            ->where('status', 'open')
            ->where(function ($query) use ($currentShift, $shiftDate) {
                $query->where('shift_id', $currentShift->id)
                    ->orWhere(function ($q) use ($shiftDate) {
                        $q->where('reset_type', TokenResetType::Daily->value)
                            ->whereDate('date', $shiftDate);
                    });
            })
            ->orderBy('opened_at')
            ->get();
    }

    /**
     * Open the token list modal for the selected queue.
     */
    public function viewQueueTokens(int $id): void
    {
        $this->viewingQueueId = $id;
        $this->showTokensModal = true;
    }

    /**
     * Close the token list modal and reset its state.
     */
    public function closeTokensModal(): void
    {
        $this->showTokensModal = false;
        $this->viewingQueueId = null;
    }

    /**
     * Get the queue currently being viewed.
     */
    #[Computed]
    public function viewedQueue(): ?ServiceQueue
    {
        if ($this->viewingQueueId === null) {
            return null;
        }

        return ServiceQueue::with(['service', 'doctor', 'tokens.invoiceItem.invoice.patient'])
            ->find($this->viewingQueueId);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Queue') }}</flux:heading>
        </div>

        @if ($this->currentShift)
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Current Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Opened at') }}: {{ $this->currentShift->opened_at->format('Y-m-d H:i') }}
                        </flux:text>
                    </div>
                    <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
                </div>
            </flux:card>
        @else
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('No Open Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">{{ __('Open a shift to view available queues.') }}</flux:text>
                    </div>
                    <flux:button variant="primary" icon="lock-open" :href="route('reception.shift')" wire:navigate>
                        {{ __('Open Shift') }}
                    </flux:button>
                </div>
            </flux:card>
        @endif

        <flux:card>
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Available Queues') }}</flux:heading>
                <flux:text class="font-semibold">{{ __('Total') }}: {{ $this->queues->count() }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                    <flux:table.column>{{ __('Reset Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Opened At') }}</flux:table.column>
                    <flux:table.column>{{ __('Tokens') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->queues as $queue)
                        <flux:table.row wire:key="queue-{{ $queue->id }}">
                            <flux:table.cell>{{ $queue->service->name }}</flux:table.cell>
                            <flux:table.cell>{{ $queue->doctor?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $queue->reset_type->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $queue->opened_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $queue->tokens_count }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    wire:click="viewQueueTokens({{ $queue->id }})"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500">
                                {{ __('No available queues found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showTokensModal" class="w-full max-w-3xl">
        @if ($this->viewedQueue)
            <div>
                <flux:heading level="2">
                    {{ __('Tokens for :service', ['service' => $this->viewedQueue->service->name]) }}
                </flux:heading>

                @if ($this->viewedQueue->doctor)
                    <flux:text class="mt-1 text-zinc-500">
                        {{ __('Doctor') }}: {{ $this->viewedQueue->doctor->name }}
                    </flux:text>
                @endif

                <flux:table class="mt-6">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Token #') }}</flux:table.column>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Created At') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->viewedQueue->tokens as $token)
                            <flux:table.row wire:key="queue-token-{{ $token->id }}">
                                <flux:table.cell class="font-semibold">{{ $token->token_number }}</flux:table.cell>
                                <flux:table.cell>{{ $token->patient?->name ?? $token->invoiceItem?->invoice?->patient?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($token->status === 'reserved')
                                        <flux:badge size="sm" color="purple">{{ __('Reserved') }}</flux:badge>
                                    @elseif ($token->status === 'waiting')
                                        <flux:badge size="sm" color="amber">{{ __('Waiting') }}</flux:badge>
                                    @elseif ($token->status === 'serving')
                                        <flux:badge size="sm" color="blue">{{ __('Serving') }}</flux:badge>
                                    @elseif ($token->status === 'served')
                                        <flux:badge size="sm" color="green">{{ __('Served') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm">{{ ucfirst($token->status) }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $token->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                    {{ __('No tokens found for this queue.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        <div class="mt-6 flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="closeTokensModal">
                {{ __('Close') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
