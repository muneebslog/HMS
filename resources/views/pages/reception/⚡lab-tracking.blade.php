<?php

use App\Actions\AdvanceOutgoingSample;
use App\Enums\LabResultsStatus;
use App\Enums\OutgoingSampleStatus;
use App\Jobs\SyncLabCaseStatus;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Lab Tracking')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $filter = 'needs_action';

    public string $keyword = '';

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $reportUploads = [];

    /** @var array<int, bool> */
    public array $expanded = [];

    /**
     * Reset pagination when the keyword changes.
     */
    public function updatedKeyword(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the filter changes.
     */
    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Summary counts for the tracking chips.
     *
     * @return array{
     *     outgoing_pending: int,
     *     outgoing_asked: int,
     *     outgoing_given: int,
     *     in_house_pending: int,
     *     ready_today: int
     * }
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'outgoing_pending' => LabInvoiceItem::query()
                ->where('is_in_house', false)
                ->where('outgoing_status', OutgoingSampleStatus::Pending)
                ->count(),
            'outgoing_asked' => LabInvoiceItem::query()
                ->where('is_in_house', false)
                ->where('outgoing_status', OutgoingSampleStatus::Asked)
                ->count(),
            'outgoing_given' => LabInvoiceItem::query()
                ->where('is_in_house', false)
                ->where('outgoing_status', OutgoingSampleStatus::Given)
                ->count(),
            'in_house_pending' => LabInvoice::query()
                ->whereHas('items', fn ($query) => $query->where('is_in_house', true))
                ->where(function ($query) {
                    $query->whereNull('lab_results_status')
                        ->orWhereIn('lab_results_status', [
                            LabResultsStatus::Unknown->value,
                            LabResultsStatus::Pending->value,
                            LabResultsStatus::Partial->value,
                        ]);
                })
                ->count(),
            'ready_today' => LabInvoice::query()
                ->whereDate('lab_results_synced_at', today())
                ->where('lab_results_status', LabResultsStatus::Ready)
                ->count(),
        ];
    }

    /**
     * Paginated lab slips for the board.
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        $query = LabInvoice::query()
            ->with(['patient.family', 'items', 'labApiLog'])
            ->latest();

        match ($this->filter) {
            'needs_action' => $query->where(function ($builder) {
                $builder->whereHas('items', function ($itemQuery) {
                    $itemQuery->where('is_in_house', false)
                        ->whereIn('outgoing_status', [
                            OutgoingSampleStatus::Pending->value,
                            OutgoingSampleStatus::Asked->value,
                            OutgoingSampleStatus::Given->value,
                        ]);
                })->orWhere(function ($inHouseQuery) {
                    $inHouseQuery->whereHas('items', fn ($itemQuery) => $itemQuery->where('is_in_house', true))
                        ->where(function ($statusQuery) {
                            $statusQuery->whereNull('lab_results_status')
                                ->orWhereIn('lab_results_status', [
                                    LabResultsStatus::Unknown->value,
                                    LabResultsStatus::Pending->value,
                                    LabResultsStatus::Partial->value,
                                ]);
                        });
                });
            }),
            'outgoing' => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('is_in_house', false)),
            'in_house' => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('is_in_house', true)),
            'ready' => $query->where(function ($builder) {
                $builder->where('lab_results_status', LabResultsStatus::Ready)
                    ->orWhere(function ($outgoingReady) {
                        $outgoingReady
                            ->whereHas('items', fn ($itemQuery) => $itemQuery->where('is_in_house', false))
                            ->whereDoesntHave('items', function ($itemQuery) {
                                $itemQuery->where('is_in_house', false)
                                    ->where(function ($statusQuery) {
                                        $statusQuery->whereNull('outgoing_status')
                                            ->orWhere('outgoing_status', '!=', OutgoingSampleStatus::Received->value);
                                    });
                            })
                            ->where(function ($inHouseReady) {
                                $inHouseReady->whereDoesntHave('items', fn ($itemQuery) => $itemQuery->where('is_in_house', true))
                                    ->orWhere('lab_results_status', LabResultsStatus::Ready);
                            });
                    });
            }),
            default => null,
        };

        if (filled($this->keyword)) {
            $term = '%'.$this->keyword.'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('invoice_number', 'like', $term)
                    ->orWhereHas('patient', function ($patientQuery) use ($term) {
                        $patientQuery->where('name', 'like', $term)
                            ->orWhere('mrn', 'like', $term)
                            ->orWhereHas('family', function ($familyQuery) use ($term) {
                                $familyQuery->where('phone', 'like', $term);
                            });
                    });
            });
        }

        return $query->paginate(15);
    }

    /**
     * Toggle expanded test list for a slip.
     */
    public function toggleExpanded(int $invoiceId): void
    {
        $this->expanded[$invoiceId] = ! ($this->expanded[$invoiceId] ?? false);
    }

    /**
     * Queue a status refresh for an in-house slip.
     */
    public function refreshStatus(int $invoiceId): void
    {
        $invoice = LabInvoice::with('items')->find($invoiceId);

        if ($invoice === null || ! $invoice->hasInHouseItems()) {
            Flux::toast(variant: 'warning', text: __('No in-house tests to refresh.'));

            return;
        }

        SyncLabCaseStatus::dispatch($invoice->id);
        unset($this->invoices, $this->summary);

        Flux::toast(variant: 'success', text: __('Status refresh queued for invoice :invoice.', [
            'invoice' => $invoice->invoice_number,
        ]));
    }

    /**
     * Advance an outgoing sample to the next status.
     */
    public function advanceOutgoing(int $itemId): void
    {
        $item = LabInvoiceItem::query()->find($itemId);

        if ($item === null || $item->is_in_house || $item->outgoing_status === null) {
            Flux::toast(variant: 'danger', text: __('Outgoing sample not found.'));

            return;
        }

        $next = $item->outgoing_status->next();

        if ($next === null) {
            Flux::toast(variant: 'warning', text: __('This sample is already received.'));

            return;
        }

        $report = $this->reportUploads[$itemId] ?? null;

        if ($next === OutgoingSampleStatus::Received && $report !== null) {
            $this->validate([
                "reportUploads.{$itemId}" => ['file', 'mimes:pdf', 'max:10240'],
            ]);
        }

        try {
            app(AdvanceOutgoingSample::class)->handle(
                $item,
                $next,
                Auth::user(),
                $next === OutgoingSampleStatus::Received ? $report : null,
            );
        } catch (\InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        unset($this->reportUploads[$itemId], $this->invoices, $this->summary);

        Flux::toast(variant: 'success', text: __('Marked as :status.', ['status' => $next->label()]));
    }

    /**
     * Upload or replace a PDF for a received outgoing sample.
     */
    public function uploadReport(int $itemId): void
    {
        $item = LabInvoiceItem::query()->find($itemId);

        if ($item === null) {
            Flux::toast(variant: 'danger', text: __('Outgoing sample not found.'));

            return;
        }

        $this->validate([
            "reportUploads.{$itemId}" => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            app(AdvanceOutgoingSample::class)->storeReportForItem(
                $item,
                $this->reportUploads[$itemId],
                Auth::user(),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                "reportUploads.{$itemId}" => $exception->getMessage(),
            ]);
        }

        unset($this->reportUploads[$itemId], $this->invoices);

        Flux::toast(variant: 'success', text: __('Report uploaded.'));
    }

    /**
     * Label for the next outgoing action button.
     */
    public function nextActionLabel(OutgoingSampleStatus $status): ?string
    {
        return match ($status->next()) {
            OutgoingSampleStatus::Asked => __('Mark asked'),
            OutgoingSampleStatus::Given => __('Mark given'),
            OutgoingSampleStatus::Received => __('Mark received'),
            default => null,
        };
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Lab Tracking') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Track outgoing sample handoffs and in-house report readiness.') }}</flux:text>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Outgoing pending') }}</flux:text>
                <flux:heading level="3">{{ $this->summary['outgoing_pending'] }}</flux:heading>
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Asked') }}</flux:text>
                <flux:heading level="3">{{ $this->summary['outgoing_asked'] }}</flux:heading>
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Given / awaiting') }}</flux:text>
                <flux:heading level="3">{{ $this->summary['outgoing_given'] }}</flux:heading>
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('In-house pending') }}</flux:text>
                <flux:heading level="3">{{ $this->summary['in_house_pending'] }}</flux:heading>
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Ready today') }}</flux:text>
                <flux:heading level="3">{{ $this->summary['ready_today'] }}</flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap gap-2">
                    @foreach ([
                        'needs_action' => __('Needs action'),
                        'outgoing' => __('Outgoing'),
                        'in_house' => __('In-house'),
                        'ready' => __('Ready'),
                        'all' => __('All'),
                    ] as $value => $label)
                        <flux:button
                            size="sm"
                            :variant="$filter === $value ? 'primary' : 'ghost'"
                            wire:click="$set('filter', '{{ $value }}')"
                        >
                            {{ $label }}
                        </flux:button>
                    @endforeach
                </div>

                <flux:input
                    wire:model.live.debounce.300ms="keyword"
                    placeholder="{{ __('Search invoice, patient, phone...') }}"
                    class="w-full lg:w-72"
                />
            </div>

            <div class="space-y-4">
                @forelse ($this->invoices as $invoice)
                    @php
                        $hasOutgoing = $invoice->items->contains(fn ($item) => ! $item->is_in_house);
                        $hasInHouse = $invoice->items->contains(fn ($item) => $item->is_in_house);
                        $isExpanded = $expanded[$invoice->id] ?? true;
                        $reportsUrl = $invoice->publicReportsUrl();
                        $typeLabel = match (true) {
                            $hasOutgoing && $hasInHouse => __('Mixed'),
                            $hasOutgoing => __('Outgoing'),
                            default => __('In-house'),
                        };
                        $typeColor = match (true) {
                            $hasOutgoing && $hasInHouse => 'amber',
                            $hasOutgoing => 'orange',
                            default => 'green',
                        };
                    @endphp

                    <div wire:key="lab-track-{{ $invoice->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:heading level="3">{{ $invoice->invoice_number }}</flux:heading>
                                    <flux:badge size="sm" :color="$typeColor">{{ $typeLabel }}</flux:badge>
                                    @if ($hasInHouse)
                                        <flux:badge size="sm" color="zinc">
                                            {{ $invoice->lab_results_status?->label() ?? __('Unknown') }}
                                        </flux:badge>
                                    @endif
                                </div>
                                <flux:text>
                                    {{ $invoice->patient?->name ?? '-' }}
                                    @if ($invoice->patient?->mrn)
                                        · {{ $invoice->patient->mrn }}
                                    @endif
                                    · {{ $invoice->patient?->contactPhone() ?? '-' }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ $invoice->created_at?->format('d M Y, g:i A') }}
                                    · {{ $invoice->items->count() }} {{ __('tests') }}
                                </flux:text>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="toggleExpanded({{ $invoice->id }})">
                                    {{ $isExpanded ? __('Hide tests') : __('Show tests') }}
                                </flux:button>

                                @if ($hasInHouse)
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-path"
                                        wire:click="refreshStatus({{ $invoice->id }})"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ __('Refresh status') }}
                                    </flux:button>

                                    @if (filled($reportsUrl))
                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            icon="arrow-top-right-on-square"
                                            href="{{ $reportsUrl }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {{ __('Show reports') }}
                                        </flux:button>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($isExpanded)
                            <div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($invoice->items as $item)
                                    <div wire:key="lab-track-item-{{ $item->id }}" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="space-y-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <flux:text class="font-medium">{{ $item->test_name }}</flux:text>
                                                @if ($item->test_code)
                                                    <flux:text class="text-xs text-zinc-500">({{ $item->test_code }})</flux:text>
                                                @endif
                                                @if ($item->is_in_house)
                                                    <flux:badge size="sm" color="green">{{ __('In-house') }}</flux:badge>
                                                    <flux:badge size="sm" :color="$item->lab_result_ready ? 'green' : 'amber'">
                                                        {{ $item->lab_result_ready ? __('Ready') : __('Pending') }}
                                                    </flux:badge>
                                                @else
                                                    <flux:badge size="sm" color="orange">{{ __('Outgoing') }}</flux:badge>
                                                    <flux:badge size="sm" color="zinc">{{ $item->outgoing_status?->label() ?? __('—') }}</flux:badge>
                                                @endif
                                            </div>
                                            @if (filled($item->sample))
                                                <flux:text class="text-xs text-zinc-500">{{ __('Specimen') }}: {{ $item->sample }}</flux:text>
                                            @endif
                                        </div>

                                        <div class="flex flex-col gap-2 sm:items-end">
                                            @if (! $item->is_in_house && $item->outgoing_status)
                                                @php $nextLabel = $this->nextActionLabel($item->outgoing_status); @endphp

                                                @if ($nextLabel)
                                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                        @if ($item->outgoing_status === App\Enums\OutgoingSampleStatus::Given)
                                                            <input
                                                                type="file"
                                                                accept="application/pdf"
                                                                wire:model="reportUploads.{{ $item->id }}"
                                                                class="block w-full max-w-xs text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm dark:text-zinc-300 dark:file:bg-zinc-800"
                                                            />
                                                            @error('reportUploads.'.$item->id)
                                                                <flux:text class="text-sm text-red-600">{{ $message }}</flux:text>
                                                            @enderror
                                                        @endif

                                                        <flux:button
                                                            size="sm"
                                                            variant="primary"
                                                            wire:click="advanceOutgoing({{ $item->id }})"
                                                            wire:loading.attr="disabled"
                                                        >
                                                            {{ $nextLabel }}
                                                        </flux:button>
                                                    </div>
                                                @elseif ($item->outgoing_status === App\Enums\OutgoingSampleStatus::Received)
                                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                        @if ($item->hasReport())
                                                            <flux:button
                                                                size="sm"
                                                                variant="ghost"
                                                                icon="document"
                                                                href="{{ route('reception.lab-tracking.report', $item) }}"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                {{ __('View PDF') }}
                                                            </flux:button>
                                                        @endif

                                                        <input
                                                            type="file"
                                                            accept="application/pdf"
                                                            wire:model="reportUploads.{{ $item->id }}"
                                                            class="block w-full max-w-xs text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm dark:text-zinc-300 dark:file:bg-zinc-800"
                                                        />
                                                        <flux:button
                                                            size="sm"
                                                            variant="ghost"
                                                            wire:click="uploadReport({{ $item->id }})"
                                                            wire:loading.attr="disabled"
                                                        >
                                                            {{ __('Upload PDF') }}
                                                        </flux:button>
                                                    </div>
                                                    @error('reportUploads.'.$item->id)
                                                        <flux:text class="text-sm text-red-600">{{ $message }}</flux:text>
                                                    @enderror
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="py-10 text-center text-zinc-500">
                        {{ __('No lab slips match this filter.') }}
                    </div>
                @endforelse
            </div>

            <div class="mt-4">
                {{ $this->invoices->links() }}
            </div>
        </flux:card>
    </div>
</div>
