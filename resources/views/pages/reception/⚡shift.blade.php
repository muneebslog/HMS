<?php

use App\Models\Shift;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Shift')] class extends Component
{
    #[Validate]
    public string $openingBalance = '';

    #[Validate]
    public string $closingBalance = '';

    /**
     * Get the validation rules for the shift form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'openingBalance' => ['required', 'numeric', 'min:0'],
            'closingBalance' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Open a new shift for the authenticated user.
     */
    public function openShift(): void
    {
        $validated = $this->validate([
            'openingBalance' => $this->rules()['openingBalance'],
        ]);

        $existingOpenShift = Shift::currentForUser(auth()->id());

        if ($existingOpenShift !== null) {
            Flux::toast(variant: 'danger', text: __('You already have an open shift.'));

            return;
        }

        Shift::create([
            'user_id' => auth()->id(),
            'opened_at' => now(),
            'opening_balance' => (float) $validated['openingBalance'],
            'status' => 'open',
        ]);

        $this->reset('openingBalance');

        Flux::toast(variant: 'success', text: __('Shift opened.'));
    }

    /**
     * Close the currently open shift.
     */
    public function closeShift(): void
    {
        $validated = $this->validate([
            'closingBalance' => $this->rules()['closingBalance'],
        ]);

        $shift = Shift::currentForUser(auth()->id());

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('No open shift found.'));

            return;
        }

        $shift->update([
            'closed_at' => now(),
            'closing_balance' => (float) $validated['closingBalance'],
            'status' => 'closed',
        ]);

        $this->reset('closingBalance');

        Flux::toast(variant: 'success', text: __('Shift closed. Total sales: :total', ['total' => number_format($shift->totalSales(), 2)]));
    }

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function activeShift(): ?Shift
    {
        return Shift::currentForUser(auth()->id());
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Shift') }}</flux:heading>
        </div>

        @if ($this->activeShift)
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Open Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Opened at') }}: {{ $this->activeShift->opened_at->format('Y-m-d H:i') }}
                        </flux:text>
                    </div>
                    <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Opening Balance') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->opening_balance, 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Walk-in Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->totalWalkInSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Lab Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->totalLabSales(), 2) }}</flux:text>
                    </div>
                </div>

                <div class="mt-4">
                    <flux:text class="text-zinc-500">{{ __('Total Sales') }}</flux:text>
                    <flux:heading level="3">{{ number_format($this->activeShift->totalSales(), 2) }}</flux:heading>
                </div>
            </flux:card>

            <flux:card>
                <flux:heading level="2">{{ __('Close Shift') }}</flux:heading>

                <form wire:submit="closeShift" class="mt-6 space-y-6">
                    <flux:field>
                        <flux:label>{{ __('Closing Balance') }}</flux:label>
                        <flux:input
                            wire:model="closingBalance"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                            placeholder="0.00"
                        />
                        <flux:error name="closingBalance" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="lock-closed">
                            {{ __('Close Shift') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        @else
            <flux:card>
                <flux:heading level="2">{{ __('Open Shift') }}</flux:heading>

                <form wire:submit="openShift" class="mt-6 space-y-6">
                    <flux:field>
                        <flux:label>{{ __('Opening Balance') }}</flux:label>
                        <flux:input
                            wire:model="openingBalance"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                            placeholder="0.00"
                        />
                        <flux:error name="openingBalance" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="lock-open">
                            {{ __('Open Shift') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        @endif
    </div>
</div>
