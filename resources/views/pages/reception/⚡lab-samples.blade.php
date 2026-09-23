<?php

use App\Actions\CreatePrintJob;
use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Lab Samples')] class extends Component
{
    #[Url]
    public string $tab = 'rider';

    /**
     * Outsourced samples, grouped by case, that still need the rider.
     *
     * @return array{not_called: Collection<int, Collection<int, LabInvoiceItem>>, waiting: Collection<int, Collection<int, LabInvoiceItem>>}
     */
    #[Computed]
    public function riderQueue(): array
    {
        $items = LabInvoiceItem::query()
            ->awaitingRider()
            ->with(['labInvoice.patient', 'askedByUser'])
            ->orderBy('id')
            ->get();

        return [
            'not_called' => $items->where('outgoing_status', OutgoingSampleStatus::Pending)->groupBy('lab_invoice_id'),
            'waiting' => $items->where('outgoing_status', OutgoingSampleStatus::Asked)->groupBy('lab_invoice_id'),
        ];
    }

    /**
     * Retakes the lab asked for that are still waiting for the patient, grouped by case.
     *
     * @return Collection<int, Collection<int, LabSampleRetake>>
     */
    #[Computed]
    public function openRetakes(): Collection
    {
        return LabSampleRetake::query()
            ->open()
            ->with(['labInvoiceItem.labInvoice.patient.family', 'requestedByUser', 'patientContactedByUser'])
            ->oldest()
            ->get()
            ->groupBy(fn (LabSampleRetake $retake) => $retake->labInvoiceItem->lab_invoice_id);
    }

    /**
     * What was done today: samples handed to the rider and retake slips printed.
     *
     * @return array{handed: Collection<int, LabInvoiceItem>, retakes: Collection<int, LabSampleRetake>}
     */
    #[Computed]
    public function today(): array
    {
        return [
            'handed' => LabInvoiceItem::query()
                ->where('is_in_house', false)
                ->whereDate('given_at', today())
                ->with(['labInvoice.patient', 'givenByUser'])
                ->latest('given_at')
                ->get(),
            'retakes' => LabSampleRetake::query()
                ->whereDate('slip_printed_at', today())
                ->with(['labInvoiceItem.labInvoice.patient', 'slipPrintedByUser'])
                ->latest('slip_printed_at')
                ->get(),
        ];
    }

    /**
     * Mark the rider as called for every sample not called yet, or just one case's samples.
     */
    public function markRiderCalled(?int $labInvoiceId = null): void
    {
        $this->authorizePage();

        $count = LabInvoiceItem::query()
            ->awaitingRider()
            ->where('outgoing_status', OutgoingSampleStatus::Pending)
            ->when($labInvoiceId, fn ($query) => $query->where('lab_invoice_id', $labInvoiceId))
            ->update([
                'outgoing_status' => OutgoingSampleStatus::Asked,
                'asked_at' => now(),
                'asked_by' => auth()->id(),
            ]);

        $this->refreshLists();

        Flux::toast(variant: 'success', text: trans_choice('Rider called for :count sample.|Rider called for :count samples.', $count, ['count' => $count]));
    }

    /**
     * Mark samples as handed to the rider: one sample, one case, or everything the rider is coming for.
     * A sample the rider collects without being called is handed over too.
     */
    public function handOver(?int $labInvoiceId = null, ?int $itemId = null): void
    {
        $this->authorizePage();

        $items = LabInvoiceItem::query()
            ->awaitingRider()
            ->when($itemId, fn ($query) => $query->whereKey($itemId))
            ->when($labInvoiceId, fn ($query) => $query->where('lab_invoice_id', $labInvoiceId))
            ->when(! $itemId && ! $labInvoiceId, fn ($query) => $query->where('outgoing_status', OutgoingSampleStatus::Asked))
            ->get();

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $item->update([
                    'outgoing_status' => OutgoingSampleStatus::Given,
                    'asked_at' => $item->asked_at ?? now(),
                    'asked_by' => $item->asked_by ?? auth()->id(),
                    'given_at' => now(),
                    'given_by' => auth()->id(),
                ]);
            }
        });

        $this->refreshLists();

        Flux::toast(variant: 'success', text: trans_choice(':count sample handed to the rider.|:count samples handed to the rider.', $items->count(), ['count' => $items->count()]));
    }

    /**
     * Note that reception has called the patient to come back for a retake.
     */
    public function markPatientContacted(int $labInvoiceId): void
    {
        $this->authorizePage();

        LabSampleRetake::query()
            ->open()
            ->whereNull('patient_contacted_at')
            ->whereHas('labInvoiceItem', fn ($item) => $item->where('lab_invoice_id', $labInvoiceId))
            ->update(['patient_contacted_at' => now(), 'patient_contacted_by' => auth()->id()]);

        $this->refreshLists();

        Flux::toast(variant: 'success', text: __('Marked as called.'));
    }

    /**
     * The patient is back: print a no-charge retake slip for the case's open retakes
     * and send those tests back to the lab's receiving queue.
     */
    public function printRetakeSlip(int $labInvoiceId): void
    {
        $this->authorizePage();

        $retakes = LabSampleRetake::query()
            ->open()
            ->whereHas('labInvoiceItem', fn ($item) => $item->where('lab_invoice_id', $labInvoiceId))
            ->get();

        $invoice = LabInvoice::find($labInvoiceId);

        if ($retakes->isEmpty() || ! $invoice) {
            Flux::toast(variant: 'danger', text: __('No retake is waiting for this case.'));

            return;
        }

        DB::transaction(function () use ($retakes, $invoice) {
            foreach ($retakes as $retake) {
                $retake->update([
                    'patient_contacted_at' => $retake->patient_contacted_at ?? now(),
                    'patient_contacted_by' => $retake->patient_contacted_by ?? auth()->id(),
                    'slip_printed_at' => now(),
                    'slip_printed_by' => auth()->id(),
                ]);
            }

            app(CreatePrintJob::class)->createLabSampleRetakeSlip($invoice, $retakes->pluck('lab_invoice_item_id')->all());
        });

        $this->refreshLists();

        Flux::toast(variant: 'success', text: __('Retake slip sent to the printer. The lab will now receive the new sample.'));
    }

    /**
     * Guard actions the same way as the page itself.
     */
    private function authorizePage(): void
    {
        abort_unless(auth()->user()?->canAccessRoute('reception.lab-samples'), 403);
    }

    /**
     * Drop cached lists after a change.
     */
    private function refreshLists(): void
    {
        unset($this->riderQueue, $this->openRetakes, $this->today);
    }
}; ?>

<div wire:poll.30s>
    @php
        $riderQueue = $this->riderQueue;
        $notCalledCount = $riderQueue['not_called']->flatten()->count();
        $waitingCount = $riderQueue['waiting']->flatten()->count();
        $retakeCount = $this->openRetakes->flatten()->count();
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading level="1">{{ __('Lab Samples') }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('Call the rider for outsourced samples, hand them over, and call patients back when the lab needs a retake.') }}</flux:text>
        </div>

        <div class="flex gap-6 border-b border-zinc-200 dark:border-zinc-700">
            <button type="button" wire:click="$set('tab', 'rider')" class="flex cursor-pointer items-center gap-1.5 border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $tab === 'rider' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}">
                <flux:icon.truck variant="micro" />
                {{ __('Rider') }}
                @if ($notCalledCount + $waitingCount > 0)
                    <flux:badge size="sm" color="purple" class="ms-1">{{ $notCalledCount + $waitingCount }}</flux:badge>
                @endif
            </button>
            <button type="button" wire:click="$set('tab', 'retakes')" class="flex cursor-pointer items-center gap-1.5 border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $tab === 'retakes' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}">
                <flux:icon.arrow-path variant="micro" />
                {{ __('Retakes') }}
                @if ($retakeCount > 0)
                    <flux:badge size="sm" color="red" class="ms-1">{{ $retakeCount }}</flux:badge>
                @endif
            </button>
            <button type="button" wire:click="$set('tab', 'today')" class="flex cursor-pointer items-center gap-1.5 border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $tab === 'today' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}">
                <flux:icon.clock variant="micro" />{{ __('Done today') }}</button>
        </div>

        @if ($tab === 'rider')
            <flux:card>
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('1. Call the rider') }}</flux:heading>
                        <flux:text class="text-sm">{{ __('Outsourced samples the rider has not been called for yet.') }}</flux:text>
                    </div>
                    @if ($notCalledCount > 0)
                        <flux:button variant="primary" icon="phone" wire:click="markRiderCalled">
                            {{ trans_choice('Rider called for :count sample|Rider called for all :count samples', $notCalledCount, ['count' => $notCalledCount]) }}
                        </flux:button>
                    @endif
                </div>

                @include('pages.reception.partials.lab-samples-rider-cases', ['cases' => $riderQueue['not_called'], 'stage' => 'not_called'])
            </flux:card>

            <flux:card>
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('2. Hand over to the rider') }}</flux:heading>
                        <flux:text class="text-sm">{{ __('Rider has been called. Mark each sample when you give it to the rider.') }}</flux:text>
                    </div>
                    @if ($waitingCount > 0)
                        <flux:button
                            variant="primary"
                            icon="hand-raised"
                            wire:click="handOver"
                            wire:confirm="{{ __('Hand all :count samples to the rider?', ['count' => $waitingCount]) }}"
                        >
                            {{ trans_choice('Hand over :count sample|Hand over all :count samples', $waitingCount, ['count' => $waitingCount]) }}
                        </flux:button>
                    @endif
                </div>

                @include('pages.reception.partials.lab-samples-rider-cases', ['cases' => $riderQueue['waiting'], 'stage' => 'waiting'])
            </flux:card>
        @elseif ($tab === 'retakes')
            <flux:card>
                <flux:heading level="2">{{ __('Samples to retake') }}</flux:heading>
                <flux:text class="mb-4 text-sm">{{ __('The lab could not use these samples. Call the patient; when they come back, print the retake slip (no charge) and send them to the lab.') }}</flux:text>

                <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->openRetakes as $labInvoiceId => $retakes)
                        @php
                            $case = $retakes->first()->labInvoiceItem->labInvoice;
                            $contacted = $retakes->every(fn ($retake) => $retake->patient_contacted_at !== null);
                        @endphp
                        <div wire:key="retake-case-{{ $labInvoiceId }}" class="flex flex-col gap-3 py-4 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $case->patient?->name ?? __('Unknown') }}</span>
                                    <span class="font-mono text-xs text-zinc-500">{{ $case->invoice_number }}</span>
                                    @if ($contacted)
                                        <flux:badge size="sm" color="sky" icon="phone">{{ __('Patient called') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">{{ __('Call patient') }}</flux:badge>
                                    @endif
                                </div>
                                <div class="mt-0.5 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    <flux:icon.phone variant="micro" class="inline" /> {{ $case->patient?->contactPhone() ?? __('No phone') }}
                                </div>
                                <ul class="mt-2 space-y-1 text-sm">
                                    @foreach ($retakes as $retake)
                                        <li wire:key="retake-{{ $retake->id }}">
                                            <span class="font-medium">{{ trim($retake->labInvoiceItem->test_name) }}</span>
                                            @if ($retake->labInvoiceItem->sample)
                                                <span class="text-zinc-500">· {{ $retake->labInvoiceItem->sample }}</span>
                                            @endif
                                            <span class="text-red-600 dark:text-red-400">· {{ $retake->reason }}</span>
                                            <span class="text-xs text-zinc-500">
                                                ({{ __('lab, :time by :name', ['time' => $retake->created_at->format('d M, g:i A'), 'name' => $retake->requestedByUser?->name ?? __('unknown')]) }}@if ($retake->patient_contacted_at){{ __('; called :time by :name', ['time' => $retake->patient_contacted_at->format('d M, g:i A'), 'name' => $retake->patientContactedByUser?->name ?? __('unknown')]) }}@endif)
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <div class="flex shrink-0 gap-2">
                                @unless ($contacted)
                                    <flux:button size="sm" icon="phone" wire:click="markPatientContacted({{ $labInvoiceId }})">{{ __('Patient called') }}</flux:button>
                                @endunless
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    icon="printer"
                                    wire:click="printRetakeSlip({{ $labInvoiceId }})"
                                    wire:confirm="{{ __('Is the patient here? This prints a no-charge retake slip and sends the test back to the lab.') }}"
                                >
                                    {{ __('Patient is here: print retake slip') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-500">{{ __('No retakes waiting.') }}</div>
                    @endforelse
                </div>
            </flux:card>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <flux:card>
                    <flux:heading level="2" class="mb-3">{{ __('Handed to rider today') }}</flux:heading>
                    <div class="flex flex-col divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        @forelse ($this->today['handed'] as $item)
                            <div wire:key="handed-{{ $item->id }}" class="flex items-start justify-between gap-3 py-2">
                                <div>
                                    <span class="font-medium uppercase">{{ $item->labInvoice->patient?->name }}</span>
                                    <span class="font-mono text-xs text-zinc-500">{{ $item->labInvoice->invoice_number }}</span>
                                    <div>{{ trim($item->test_name) }} @if ($item->sample)<span class="text-zinc-500">· {{ $item->sample }}</span>@endif</div>
                                </div>
                                <div class="shrink-0 text-right text-xs text-zinc-500">
                                    {{ $item->given_at->format('g:i A') }}
                                    <div>{{ $item->givenByUser?->name }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="py-6 text-center text-zinc-500">{{ __('Nothing handed over today.') }}</div>
                        @endforelse
                    </div>
                </flux:card>

                <flux:card>
                    <flux:heading level="2" class="mb-3">{{ __('Retake slips printed today') }}</flux:heading>
                    <div class="flex flex-col divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        @forelse ($this->today['retakes'] as $retake)
                            <div wire:key="printed-retake-{{ $retake->id }}" class="flex items-start justify-between gap-3 py-2">
                                <div>
                                    <span class="font-medium uppercase">{{ $retake->labInvoiceItem->labInvoice->patient?->name }}</span>
                                    <span class="font-mono text-xs text-zinc-500">{{ $retake->labInvoiceItem->labInvoice->invoice_number }}</span>
                                    <div>{{ trim($retake->labInvoiceItem->test_name) }} <span class="text-zinc-500">· {{ $retake->reason }}</span></div>
                                </div>
                                <div class="shrink-0 text-right text-xs text-zinc-500">
                                    {{ $retake->slip_printed_at->format('g:i A') }}
                                    <div>{{ $retake->slipPrintedByUser?->name }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="py-6 text-center text-zinc-500">{{ __('No retake slips printed today.') }}</div>
                        @endforelse
                    </div>
                </flux:card>
            </div>
        @endif
    </div>
</div>
