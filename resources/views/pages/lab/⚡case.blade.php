<?php

use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lab Case')] class extends Component
{
    public LabInvoice $labInvoice;

    /**
     * Load everything the case page shows up front.
     */
    public function mount(): void
    {
        $this->labInvoice->load(['patient.family', 'referredByDoctor', 'items.labTest']);
    }

    /**
     * Get the case's tests: in-house first, then send-out, each in the order they were billed.
     *
     * @return \Illuminate\Support\Collection<int, LabInvoiceItem>
     */
    #[Computed]
    public function items()
    {
        return $this->labInvoice->items
            ->sortBy([['is_in_house', 'desc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Describe where a test stands, for the status badge.
     *
     * @return array{label: string, color: string}
     */
    public function itemStatus(LabInvoiceItem $item): array
    {
        if ($item->is_in_house) {
            return $item->isDone()
                ? ['label' => __('Results ready'), 'color' => 'green']
                : ['label' => __('Awaiting results'), 'color' => 'amber'];
        }

        if (filled($item->report_path)) {
            return ['label' => __('Report uploaded'), 'color' => 'green'];
        }

        $status = $item->outgoing_status ?? OutgoingSampleStatus::Pending;

        return [
            'label' => __('Send-out: :status', ['status' => $status->label()]),
            'color' => $status === OutgoingSampleStatus::Received ? 'green' : 'sky',
        ];
    }
}; ?>

<div>
    @php
        $patient = $labInvoice->patient;
        $done = $labInvoice->doneItemsCount();
        $total = $labInvoice->items->count();
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('lab.cases')" wire:navigate class="self-start">
            {{ __('Lab Cases') }}
        </flux:button>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1" class="uppercase">{{ $patient?->name ?? __('Unknown patient') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Receipt :number · registered :date', ['number' => $labInvoice->invoice_number, 'date' => $labInvoice->created_at->format('d M Y, g:i A')]) }}
                </flux:text>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-sm tabular-nums text-zinc-500">{{ __(':done of :total tests done', ['done' => $done, 'total' => $total]) }}</span>
                @if ($labInvoice->isComplete())
                    <flux:badge color="green" icon="check-circle">{{ __('Complete') }}</flux:badge>
                @else
                    <flux:badge color="amber" icon="clock">{{ __('Awaiting results') }}</flux:badge>
                @endif
            </div>
        </div>

        <flux:card>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <dt class="text-zinc-500">{{ __('MR No') }}</dt>
                    <dd class="font-medium">{{ $patient?->mrn ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Age') }}</dt>
                    <dd class="font-medium">{{ $patient?->age !== null ? __(':age years', ['age' => $patient->age]) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Sex') }}</dt>
                    <dd class="font-medium">{{ $patient?->gender ? ucfirst($patient->gender) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Phone') }}</dt>
                    <dd class="font-medium">{{ $patient?->contactPhone() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Referred By') }}</dt>
                    <dd class="font-medium">{{ $labInvoice->referredByDoctor?->name ?? __('Self') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Receipt No') }}</dt>
                    <dd class="font-mono font-medium">{{ $labInvoice->invoice_number }}</dd>
                </div>
            </dl>
        </flux:card>

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Tests') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Test') }}</flux:table.column>
                    <flux:table.column>{{ __('Sample') }}</flux:table.column>
                    <flux:table.column>{{ __('Done at') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->items as $item)
                        @php($status = $this->itemStatus($item))
                        <flux:table.row wire:key="case-item-{{ $item->id }}">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ trim($item->test_name) }}</div>
                                @if (filled($item->labTest?->display_name))
                                    <div class="text-xs text-zinc-500">{{ $item->labTest->display_name }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $item->sample ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$item->is_in_house ? 'zinc' : 'purple'">
                                    {{ $item->is_in_house ? __('In-house') : __('Send-out') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
</div>
