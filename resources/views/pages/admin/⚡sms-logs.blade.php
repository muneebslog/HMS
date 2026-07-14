<?php

use App\Enums\SmsStatus;
use App\Models\SmsLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('SMS Logs')] class extends Component
{
    public string $statusFilter = 'all';

    public string $phoneSearch = '';

    public ?int $selectedLogId = null;

    public bool $showLogModal = false;

    /**
     * Get a paginated list of SMS logs with their doctors.
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        $query = SmsLog::with('doctor')->latest();

        if ($this->statusFilter !== 'all' && in_array($this->statusFilter, SmsStatus::values(), true)) {
            $query->where('status', $this->statusFilter);
        }

        if (filled($this->phoneSearch)) {
            $query->where('phone', 'like', '%'.$this->phoneSearch.'%');
        }

        return $query->paginate(20);
    }

    /**
     * Get the SMS log currently selected for detail viewing.
     */
    #[Computed]
    public function selectedLog(): ?SmsLog
    {
        if ($this->selectedLogId === null) {
            return null;
        }

        return SmsLog::with('doctor')->find($this->selectedLogId);
    }

    /**
     * Open the detail modal for the selected log.
     */
    public function viewLog(int $id): void
    {
        $this->selectedLogId = $id;
        $this->showLogModal = true;
    }

    /**
     * Close the detail modal and reset its state.
     */
    public function closeLogModal(): void
    {
        $this->showLogModal = false;
        $this->selectedLogId = null;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('SMS Logs') }}</flux:heading>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Messages') }}</flux:heading>

                <div class="flex flex-col gap-4 sm:flex-row">
                    <flux:input
                        wire:model.live.debounce.300ms="phoneSearch"
                        placeholder="{{ __('Search phone...') }}"
                        class="w-full sm:w-56"
                    />

                    <flux:select wire:model.live="statusFilter" class="w-full sm:w-44">
                        <option value="all">{{ __('All statuses') }}</option>
                        @foreach (App\Enums\SmsStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ID') }}</flux:table.column>
                    <flux:table.column>{{ __('Phone') }}</flux:table.column>
                    <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                    <flux:table.column>{{ __('Token #') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column>{{ __('Sent') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->logs as $log)
                        <flux:table.row wire:key="sms-log-{{ $log->id }}">
                            <flux:table.cell>#{{ $log->id }}</flux:table.cell>
                            <flux:table.cell>{{ $log->phone }}</flux:table.cell>
                            <flux:table.cell>{{ $log->doctor?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $log->token_number }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($log->status === App\Enums\SmsStatus::Queued)
                                    <flux:badge size="sm" color="amber">{{ $log->status->label() }}</flux:badge>
                                @elseif ($log->status === App\Enums\SmsStatus::Sent)
                                    <flux:badge size="sm" color="green">{{ $log->status->label() }}</flux:badge>
                                @else
                                    <flux:tooltip :content="filled($log->provider_response) ? Str::limit($log->provider_response, 200) : __('Click View for details')" position="top">
                                        <flux:badge size="sm" color="red">{{ $log->status->label() }}</flux:badge>
                                    </flux:tooltip>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $log->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $log->sent_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    wire:click="viewLog({{ $log->id }})"
                                >
                                    {{ __('View') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                {{ __('No SMS logs found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->logs->links() }}
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showLogModal" class="w-full max-w-lg">
        @if ($this->selectedLog)
            <flux:heading level="2">{{ __('SMS Details') }}</flux:heading>

            <div class="mt-4 space-y-3 text-sm">
                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Phone') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedLog->phone }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Doctor') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedLog->doctor?->name ?? '-' }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Token #') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedLog->token_number }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Status') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedLog->status->label() }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Created') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedLog->created_at->format('Y-m-d H:i:s') }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Sent') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedLog->sent_at?->format('Y-m-d H:i:s') ?? '-' }}</flux:text>
                </div>

                <div>
                    <flux:text class="text-zinc-500">{{ __('Message') }}</flux:text>
                    <flux:text class="mt-1 block whitespace-pre-wrap">{{ $this->selectedLog->message ?? '-' }}</flux:text>
                </div>

                @if (filled($this->selectedLog->provider_response))
                    <div>
                        <flux:text class="text-zinc-500">
                            {{ $this->selectedLog->status === App\Enums\SmsStatus::Failed ? __('Failure Reason') : __('Provider Response') }}
                        </flux:text>
                        <flux:text class="mt-1 block whitespace-pre-wrap">{{ $this->selectedLog->provider_response }}</flux:text>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeLogModal">
                    {{ __('Close') }}
                </flux:button>
            </div>
        @endif
    </flux:modal>
</div>
