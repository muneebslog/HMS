<?php

use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sample Receiving')] class extends Component
{
    #[Url]
    public string $tab = 'receive';

    public bool $showRetakeModal = false;

    public ?int $retakeItemId = null;

    public string $retakeReason = '';

    public string $retakeOtherReason = '';

    /**
     * In-house samples the lab should be receiving from reception, grouped by case (oldest first).
     *
     * @return Collection<int, Collection<int, LabInvoiceItem>>
     */
    #[Computed]
    public function awaiting(): Collection
    {
        return LabInvoiceItem::query()
            ->awaitingSample()
            ->with(['labInvoice.patient', 'latestRetake'])
            ->orderBy('lab_invoice_id')
            ->orderBy('id')
            ->get()
            ->groupBy('lab_invoice_id');
    }

    /**
     * Retakes waiting for the patient to come back.
     *
     * @return Collection<int, LabSampleRetake>
     */
    #[Computed]
    public function openRetakes(): Collection
    {
        return LabSampleRetake::query()
            ->open()
            ->with(['labInvoiceItem.labInvoice.patient', 'requestedByUser', 'patientContactedByUser'])
            ->oldest()
            ->get();
    }

    /**
     * Samples received today, newest first.
     *
     * @return Collection<int, LabInvoiceItem>
     */
    #[Computed]
    public function receivedToday(): Collection
    {
        return LabInvoiceItem::query()
            ->where('is_in_house', true)
            ->whereDate('sample_received_at', today())
            ->whereNotNull('sample_received_by')
            ->with(['labInvoice.patient', 'sampleReceivedByUser'])
            ->latest('sample_received_at')
            ->get();
    }

    /**
     * Confirm the lab has one sample, or every awaited sample of a case.
     */
    public function receive(?int $labInvoiceId = null, ?int $itemId = null): void
    {
        $this->authorizePage();

        if (! $labInvoiceId && ! $itemId) {
            return;
        }

        $count = LabInvoiceItem::query()
            ->awaitingSample()
            ->when($itemId, fn ($query) => $query->whereKey($itemId))
            ->when($labInvoiceId, fn ($query) => $query->where('lab_invoice_id', $labInvoiceId))
            ->update(['sample_received_at' => now(), 'sample_received_by' => auth()->id()]);

        $this->refreshLists();

        Flux::toast(variant: 'success', text: trans_choice(':count sample received.|:count samples received.', $count, ['count' => $count]));
    }

    /**
     * Open the retake form for a test.
     */
    public function openRetake(int $itemId): void
    {
        $this->authorizePage();

        $this->retakeItemId = $itemId;
        $this->retakeReason = '';
        $this->retakeOtherReason = '';
        $this->resetValidation();
        $this->showRetakeModal = true;
    }

    /**
     * Ask reception to call the patient back for a new sample. The test leaves the lab's
     * queue until reception prints the retake slip.
     */
    public function requestRetake(): void
    {
        $this->authorizePage();

        $this->validate([
            'retakeReason' => ['required', 'string', Rule::in([...LabSampleRetake::REASONS, 'other'])],
            'retakeOtherReason' => ['required_if:retakeReason,other', 'nullable', 'string', 'max:255'],
        ], [], [
            'retakeReason' => __('reason'),
            'retakeOtherReason' => __('reason'),
        ]);

        $item = LabInvoiceItem::query()
            ->where('is_in_house', true)
            ->whereNull('results_completed_at')
            ->whereDoesntHave('retakes', fn ($retakes) => $retakes->open())
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))
            ->find($this->retakeItemId);

        if (! $item) {
            $this->showRetakeModal = false;
            Flux::toast(variant: 'danger', text: __('A retake cannot be requested for this test.'));

            return;
        }

        DB::transaction(function () use ($item) {
            $item->retakes()->create([
                'reason' => $this->retakeReason === 'other' ? trim($this->retakeOtherReason) : $this->retakeReason,
                'requested_by' => auth()->id(),
            ]);

            $item->update(['sample_received_at' => null, 'sample_received_by' => null]);
        });

        $this->showRetakeModal = false;
        $this->retakeItemId = null;
        $this->refreshLists();

        Flux::toast(variant: 'success', text: __('Retake requested. Reception will call the patient.'));
    }

    /**
     * Guard actions the same way as the page itself.
     */
    private function authorizePage(): void
    {
        abort_unless(auth()->user()?->canAccessRoute('lab.samples'), 403);
    }

    /**
     * Drop cached lists after a change.
     */
    private function refreshLists(): void
    {
        unset($this->awaiting, $this->openRetakes, $this->receivedToday);
    }
}; ?>

<div wire:poll.30s>
    @php
        $awaitingCount = $this->awaiting->flatten()->count();
        $retakeCount = $this->openRetakes->count();
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading level="1">{{ __('Sample Receiving') }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('Confirm each in-house sample you get from reception. If a sample is missing or not usable, ask for a retake.') }}</flux:text>
        </div>

        <div class="flex gap-6 border-b border-zinc-200 dark:border-zinc-700">
            <button type="button" wire:click="$set('tab', 'receive')" class="flex cursor-pointer items-center gap-1.5 border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $tab === 'receive' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}">
                <flux:icon.inbox-arrow-down variant="micro" />
                {{ __('To receive') }}
                @if ($awaitingCount > 0)
                    <flux:badge size="sm" color="amber" class="ms-1">{{ $awaitingCount }}</flux:badge>
                @endif
            </button>
            <button type="button" wire:click="$set('tab', 'retakes')" class="flex cursor-pointer items-center gap-1.5 border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $tab === 'retakes' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}">
                <flux:icon.arrow-path variant="micro" />
                {{ __('Retakes') }}
                @if ($retakeCount > 0)
                    <flux:badge size="sm" color="red" class="ms-1">{{ $retakeCount }}</flux:badge>
                @endif
            </button>
            <button type="button" wire:click="$set('tab', 'received')" class="flex cursor-pointer items-center gap-1.5 border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $tab === 'received' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}">
                <flux:icon.check-circle variant="micro" />{{ __('Received today') }}</button>
        </div>

        @if ($tab === 'receive')
            <flux:card>
                <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->awaiting as $labInvoiceId => $items)
                        @php($case = $items->first()->labInvoice)
                        <div wire:key="awaiting-case-{{ $labInvoiceId }}" class="flex flex-col gap-3 py-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-base font-semibold uppercase text-zinc-900 dark:text-zinc-100">{{ $case->patient?->name ?? __('Unknown') }}</span>
                                    <span class="font-mono text-xs text-zinc-500">{{ $case->invoice_number }}</span>
                                    <span class="text-xs text-zinc-500">
                                        {{ $case->patient?->age !== null ? __(':age Y', ['age' => $case->patient->age]) : '' }}
                                        {{ $case->patient?->gender ? ucfirst($case->patient->gender) : '' }}
                                        · {{ $case->created_at->format('d M, g:i A') }}
                                    </span>
                                </div>
                                <ul class="mt-2 space-y-1.5 text-sm">
                                    @foreach ($items as $item)
                                        <li wire:key="awaiting-item-{{ $item->id }}" class="flex flex-wrap items-center gap-2">
                                            <flux:badge size="sm" color="zinc">{{ $item->sample ?: __('Sample not set') }}</flux:badge>
                                            <span class="font-medium">{{ trim($item->test_name) }}</span>
                                            @if ($item->latestRetake)
                                                <flux:badge size="sm" color="orange" icon="arrow-path">{{ __('Retake: :reason', ['reason' => $item->latestRetake->reason]) }}</flux:badge>
                                            @endif
                                            <flux:button size="xs" variant="ghost" icon="check" wire:click="receive(null, {{ $item->id }})">{{ __('Received') }}</flux:button>
                                            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="openRetake({{ $item->id }})">{{ __('Retake') }}</flux:button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <div class="flex shrink-0 gap-2">
                                <flux:button size="sm" :href="route('lab.cases.show', $labInvoiceId)" wire:navigate>{{ __('Open case') }}</flux:button>
                                <flux:button size="sm" variant="primary" icon="check" wire:click="receive({{ $labInvoiceId }})">
                                    {{ $items->count() > 1 ? __('Received all :count', ['count' => $items->count()]) : __('Received') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-500">{{ __('No samples waiting. New lab slips appear here automatically.') }}</div>
                    @endforelse
                </div>
            </flux:card>
        @elseif ($tab === 'retakes')
            <flux:card>
                <flux:text class="mb-2 text-sm">{{ __('Waiting for the patient to come back. When reception prints the retake slip, the test returns to "To receive".') }}</flux:text>
                <div class="flex flex-col divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    @forelse ($this->openRetakes as $retake)
                        @php($case = $retake->labInvoiceItem->labInvoice)
                        <div wire:key="lab-retake-{{ $retake->id }}" class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <span class="font-medium uppercase">{{ $case->patient?->name ?? __('Unknown') }}</span>
                                <span class="font-mono text-xs text-zinc-500">{{ $case->invoice_number }}</span>
                                <div>
                                    {{ trim($retake->labInvoiceItem->test_name) }}
                                    <span class="text-red-600 dark:text-red-400">· {{ $retake->reason }}</span>
                                    <span class="text-xs text-zinc-500">({{ __(':time by :name', ['time' => $retake->created_at->format('d M, g:i A'), 'name' => $retake->requestedByUser?->name ?? __('unknown')]) }})</span>
                                </div>
                            </div>
                            @if ($retake->patient_contacted_at)
                                <flux:badge size="sm" color="sky" icon="phone">{{ __('Patient called :time', ['time' => $retake->patient_contacted_at->format('g:i A')]) }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Reception to call patient') }}</flux:badge>
                            @endif
                        </div>
                    @empty
                        <div class="py-8 text-center text-zinc-500">{{ __('No retakes waiting.') }}</div>
                    @endforelse
                </div>
            </flux:card>
        @else
            <flux:card>
                <div class="flex flex-col divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    @forelse ($this->receivedToday as $item)
                        <div wire:key="received-{{ $item->id }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <span class="font-medium uppercase">{{ $item->labInvoice->patient?->name ?? __('Unknown') }}</span>
                                <span class="font-mono text-xs text-zinc-500">{{ $item->labInvoice->invoice_number }}</span>
                                <div>{{ trim($item->test_name) }} @if ($item->sample)<span class="text-zinc-500">· {{ $item->sample }}</span>@endif</div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-zinc-500">{{ __(':time by :name', ['time' => $item->sample_received_at->format('g:i A'), 'name' => $item->sampleReceivedByUser?->name ?? __('unknown')]) }}</span>
                                @unless ($item->results_completed_at)
                                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="openRetake({{ $item->id }})">{{ __('Retake') }}</flux:button>
                                @endunless
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-zinc-500">{{ __('Nothing received yet today.') }}</div>
                    @endforelse
                </div>
            </flux:card>
        @endif
    </div>

    <flux:modal wire:model="showRetakeModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Ask for a retake') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Reception will call the patient back and print a no-charge slip when they come.') }}</flux:text>

        <form wire:submit="requestRetake" class="mt-6 space-y-4">
            <flux:radio.group wire:model.live="retakeReason" :label="__('Why?')">
                @foreach (LabSampleRetake::REASONS as $reason)
                    <flux:radio :value="$reason" :label="__($reason)" />
                @endforeach
                <flux:radio value="other" :label="__('Other')" />
            </flux:radio.group>

            @if ($retakeReason === 'other')
                <flux:input wire:model="retakeOtherReason" :label="__('Reason')" />
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showRetakeModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="danger" icon="arrow-path">{{ __('Ask for retake') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
