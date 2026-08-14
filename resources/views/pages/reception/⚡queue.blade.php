<?php

use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Services\TokenAdministrationService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Queue')] class extends Component
{
    public ?int $viewingQueueId = null;

    public bool $showTokensModal = false;

    public bool $showEditPatientModal = false;

    public bool $showConfirmModal = false;

    public ?int $editingTokenId = null;

    public string $editPatientName = '';

    public string $editPatientPhone = '';

    public ?int $confirmingTokenId = null;

    public ?string $confirmingAction = null;

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function currentShift(): ?Shift
    {
        return Shift::current();
    }

    /**
     * Determine whether the authenticated user is an admin.
     */
    #[Computed]
    public function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
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
            return new Collection;
        }

        return ServiceQueue::with(['service', 'doctor'])
            ->withCount('tokens')
            ->where('status', 'open')
            ->forShift($currentShift)
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
        $this->closeEditPatientModal();
        $this->closeConfirmModal();
    }

    /**
     * Open the edit patient modal for a token.
     */
    public function openEditPatient(int $tokenId): void
    {
        $this->ensureAdmin();

        $token = QueueToken::with(['patient.family', 'invoiceItem.invoice.patient.family'])->find($tokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Token not found.'));

            return;
        }

        $patient = $token->patient ?? $token->invoiceItem?->invoice?->patient;

        if ($patient === null) {
            Flux::toast(variant: 'danger', text: __('This token has no linked patient.'));

            return;
        }

        $this->editingTokenId = $token->id;
        $this->editPatientName = $patient->name;
        $this->editPatientPhone = $patient->contactPhone() ?? '';
        $this->showEditPatientModal = true;
        $this->resetValidation();
    }

    /**
     * Close the edit patient modal.
     */
    public function closeEditPatientModal(): void
    {
        $this->showEditPatientModal = false;
        $this->editingTokenId = null;
        $this->editPatientName = '';
        $this->editPatientPhone = '';
        $this->resetValidation();
    }

    /**
     * Save edited patient details for a token.
     */
    public function savePatientDetails(): void
    {
        $this->ensureAdmin();

        $validated = $this->validate([
            'editPatientName' => ['required', 'string', 'max:255'],
            'editPatientPhone' => ['nullable', 'digits:11'],
        ]);

        if ($this->editingTokenId === null) {
            return;
        }

        $token = QueueToken::find($this->editingTokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Token not found.'));

            return;
        }

        try {
            app(TokenAdministrationService::class)->updatePatientDetails(
                auth()->user(),
                $token,
                $validated['editPatientName'],
                filled($validated['editPatientPhone']) ? $validated['editPatientPhone'] : null,
            );

            $this->closeEditPatientModal();
            unset($this->viewedQueue);

            Flux::toast(variant: 'success', text: __('Patient details updated.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /**
     * Open a confirmation modal for a destructive token action.
     */
    public function openConfirmAction(int $tokenId, string $action): void
    {
        $this->ensureAdmin();

        if (! in_array($action, ['not_arrived', 'not_served', 'revert_reserved'], true)) {
            Flux::toast(variant: 'danger', text: __('Invalid action.'));

            return;
        }

        $token = QueueToken::find($tokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Token not found.'));

            return;
        }

        $this->confirmingTokenId = $tokenId;
        $this->confirmingAction = $action;
        $this->showConfirmModal = true;
    }

    /**
     * Close the confirmation modal.
     */
    public function closeConfirmModal(): void
    {
        $this->showConfirmModal = false;
        $this->confirmingTokenId = null;
        $this->confirmingAction = null;
    }

    /**
     * Execute the confirmed admin token action.
     */
    public function confirmAction(): void
    {
        $this->ensureAdmin();

        if ($this->confirmingTokenId === null || $this->confirmingAction === null) {
            return;
        }

        $token = QueueToken::find($this->confirmingTokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Token not found.'));
            $this->closeConfirmModal();

            return;
        }

        try {
            $service = app(TokenAdministrationService::class);
            $admin = auth()->user();

            $message = match ($this->confirmingAction) {
                'not_arrived' => tap(
                    __('Token marked as not arrived and invoice cancelled.'),
                    fn () => $service->markAsNotArrived($admin, $token)
                ),
                'not_served' => tap(
                    __('Token marked as not served.'),
                    fn () => $service->markAsNotServed($admin, $token)
                ),
                'revert_reserved' => tap(
                    __('Reserved token reverted.'),
                    fn () => $service->revertReserved($admin, $token)
                ),
                default => throw new \RuntimeException(__('Invalid action.')),
            };

            $this->closeConfirmModal();
            unset($this->viewedQueue);

            Flux::toast(variant: 'success', text: $message);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
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

        return ServiceQueue::with(['service', 'doctor', 'tokens.patient.family', 'tokens.invoiceItem.invoice.patient.family'])
            ->find($this->viewingQueueId);
    }

    /**
     * Get the token currently pending confirmation.
     */
    #[Computed]
    public function confirmingToken(): ?QueueToken
    {
        if ($this->confirmingTokenId === null) {
            return null;
        }

        return QueueToken::with(['patient', 'invoiceItem.invoice'])->find($this->confirmingTokenId);
    }

    /**
     * Abort unless the current user is an admin.
     */
    private function ensureAdmin(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
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

    <flux:modal wire:model="showTokensModal" class="w-full max-w-5xl">
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
                        <flux:table.column>{{ __('Phone') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Created At') }}</flux:table.column>
                        @if ($this->isAdmin)
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        @endif
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->viewedQueue->tokens as $token)
                            @php
                                $patient = $token->patient ?? $token->invoiceItem?->invoice?->patient;
                                $canMarkNotArrived = $token->origin === 'reservation'
                                    && $token->arrived_at !== null
                                    && in_array($token->status, ['waiting', 'serving'], true);
                                $canMarkNotServed = $token->status === 'served';
                                $canRevertReserved = $token->status === 'reserved'
                                    && $token->arrived_at === null
                                    && $token->invoice_item_id === null;
                            @endphp
                            <flux:table.row wire:key="queue-token-{{ $token->id }}">
                                <flux:table.cell class="font-semibold">{{ $token->token_number }}</flux:table.cell>
                                <flux:table.cell>{{ $patient?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $patient?->contactPhone() ?? '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($token->status === 'reserved')
                                        <flux:badge size="sm" color="purple">{{ __('Reserved') }}</flux:badge>
                                    @elseif ($token->status === 'waiting')
                                        <flux:badge size="sm" color="amber">{{ __('Waiting') }}</flux:badge>
                                    @elseif ($token->status === 'serving')
                                        <flux:badge size="sm" color="blue">{{ __('Serving') }}</flux:badge>
                                    @elseif ($token->status === 'served')
                                        <flux:badge size="sm" color="green">{{ __('Served') }}</flux:badge>
                                    @elseif ($token->status === 'skipped')
                                        <flux:badge size="sm" color="zinc">{{ __('Skipped') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm">{{ ucfirst($token->status) }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $token->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                @if ($this->isAdmin)
                                    <flux:table.cell>
                                        <div class="flex flex-wrap justify-end gap-1">
                                            @if ($patient)
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    icon="pencil-square"
                                                    wire:click="openEditPatient({{ $token->id }})"
                                                />
                                            @endif
                                            @if ($canMarkNotArrived)
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    wire:click="openConfirmAction({{ $token->id }}, 'not_arrived')"
                                                >
                                                    {{ __('Not arrived') }}
                                                </flux:button>
                                            @endif
                                            @if ($canMarkNotServed)
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    wire:click="openConfirmAction({{ $token->id }}, 'not_served')"
                                                >
                                                    {{ __('Not served') }}
                                                </flux:button>
                                            @endif
                                            @if ($canRevertReserved)
                                                <flux:button
                                                    size="sm"
                                                    variant="danger"
                                                    wire:click="openConfirmAction({{ $token->id }}, 'revert_reserved')"
                                                >
                                                    {{ __('Revert') }}
                                                </flux:button>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                @endif
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="{{ $this->isAdmin ? 6 : 5 }}" class="text-center text-zinc-500">
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

    <flux:modal wire:model="showEditPatientModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit Patient') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Update the patient name and phone number for this token.') }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="editPatientName" type="text" required />
                <flux:error name="editPatientName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Phone') }}</flux:label>
                <flux:input
                    wire:model="editPatientPhone"
                    type="text"
                    inputmode="numeric"
                    maxlength="11"
                    placeholder="03001234567"
                />
                <flux:error name="editPatientPhone" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeEditPatientModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="button" variant="primary" wire:click="savePatientDetails">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showConfirmModal" class="md:w-96">
        @php
            $confirmToken = $this->confirmingToken;
            $invoiceNumber = $confirmToken?->invoiceItem?->invoice?->invoice_number;
        @endphp
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Confirm Action') }}</flux:heading>
                @if ($this->confirmingAction === 'not_arrived')
                    <flux:text class="mt-2">
                        {{ __('Mark this arrived reservation as not arrived? The linked invoice will be cancelled.') }}
                    </flux:text>
                    @if ($invoiceNumber)
                        <flux:text class="mt-2 font-semibold">
                            {{ __('Invoice') }}: {{ $invoiceNumber }}
                        </flux:text>
                    @endif
                @elseif ($this->confirmingAction === 'not_served')
                    <flux:text class="mt-2">
                        {{ __('Mark this served token as not served? It will return to waiting.') }}
                    </flux:text>
                @elseif ($this->confirmingAction === 'revert_reserved')
                    <flux:text class="mt-2">
                        {{ __('Remove this reserved token? This cannot be undone.') }}
                    </flux:text>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeConfirmModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="{{ $this->confirmingAction === 'revert_reserved' || $this->confirmingAction === 'not_arrived' ? 'danger' : 'primary' }}"
                    wire:click="confirmAction"
                >
                    {{ __('Confirm') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
