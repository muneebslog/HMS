<?php

use App\Actions\AttachPartnerLabReport;
use App\Actions\StorePartnerLabReport;
use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoiceItem;
use App\Models\PartnerLabReport;
use App\Services\CeoLabOverview;
use App\Services\PartnerLab\PartnerLabMatcher;
use App\Services\PartnerLab\PartnerLabSync;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Outsourced Tests')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * The status tabs, in display order.
     *
     * @var list<string>
     */
    public const TABS = ['open', 'reception', 'partner_lab', 'late', 'received', 'all'];

    #[Url]
    public string $tab = 'open';

    public string $fromDate = '';

    public string $toDate = '';

    public string $search = '';

    public bool $showUploadModal = false;

    public ?int $uploadItemId = null;

    public ?TemporaryUploadedFile $reportUpload = null;

    public bool $showAttachModal = false;

    public ?int $attachReportId = null;

    public ?int $attachItemId = null;

    /**
     * Default the date filter to the window outsourced tests are tracked for.
     */
    public function mount(): void
    {
        $this->fromDate = now()->subDays(CeoLabOverview::OUTSOURCED_OPEN_DAYS - 1)->toDateString();
        $this->toDate = now()->toDateString();

        if (! in_array($this->tab, [...self::TABS, 'partner'], true)) {
            $this->tab = 'open';
        }
    }

    /**
     * Ready partner lab reports not attached or ignored yet, newest first.
     *
     * @return \Illuminate\Support\Collection<int, PartnerLabReport>
     */
    #[Computed]
    public function partnerReports()
    {
        return PartnerLabReport::query()->waiting()->latest('registered_at')->limit(100)->get();
    }

    /**
     * The likely outsourced test for each waiting partner report, keyed by report id.
     *
     * @return array<int, array{item: LabInvoiceItem, score: int}|null>
     */
    #[Computed]
    public function partnerSuggestions(): array
    {
        $matcher = app(PartnerLabMatcher::class);

        return $this->partnerReports
            ->mapWithKeys(fn (PartnerLabReport $report) => [$report->id => $matcher->suggest($report)])
            ->all();
    }

    /**
     * How many ready partner reports are waiting to be attached.
     */
    #[Computed]
    public function waitingPartnerCount(): int
    {
        return PartnerLabReport::query()->waiting()->count();
    }

    /**
     * When the partner portal was last read successfully, for the "last checked" note.
     */
    #[Computed]
    public function partnerLastSeenAt(): ?CarbonImmutable
    {
        if ($lastSynced = PartnerLabSync::lastSyncedAt()) {
            return $lastSynced;
        }

        $lastSeen = PartnerLabReport::query()->max('last_seen_at');

        return $lastSeen ? CarbonImmutable::parse($lastSeen) : null;
    }

    /**
     * Outsourced tests to offer in the attach form, best match for the chosen report first.
     *
     * @return \Illuminate\Support\Collection<int, array{item: LabInvoiceItem, score: ?int}>
     */
    #[Computed]
    public function attachItemOptions()
    {
        $report = $this->attachReportId ? PartnerLabReport::find($this->attachReportId) : null;
        $matcher = app(PartnerLabMatcher::class);
        $candidates = $matcher->candidateQuery()
            ->where('created_at', '>=', now()->subDays(CeoLabOverview::OUTSOURCED_OPEN_DAYS))
            ->latest()
            ->get();

        return $report
            ? $matcher->rank($report, $candidates)
            : $candidates->map(fn (LabInvoiceItem $item) => ['item' => $item, 'score' => null]);
    }

    /**
     * Waiting partner reports to offer in the attach form, best match for the chosen test first.
     *
     * @return \Illuminate\Support\Collection<int, array{report: PartnerLabReport, score: ?int}>
     */
    #[Computed]
    public function attachReportOptions()
    {
        $item = $this->attachItemId ? LabInvoiceItem::query()->with('labInvoice.patient')->find($this->attachItemId) : null;
        $matcher = app(PartnerLabMatcher::class);
        $reports = PartnerLabReport::query()->waiting()->latest('registered_at')->limit(100)->get();

        if ($this->attachReportId && ! $reports->contains('id', $this->attachReportId)) {
            $reports->prepend(PartnerLabReport::findOrFail($this->attachReportId));
        }

        return $reports
            ->map(fn (PartnerLabReport $report) => ['report' => $report, 'score' => $item ? $matcher->score($report, $item) : null])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Open the attach form for a partner report, with its likely test chosen.
     */
    public function openAttachForReport(int $reportId): void
    {
        abort_unless($this->canManageResults, 403);

        $this->attachReportId = PartnerLabReport::query()->findOrFail($reportId)->id;
        $this->attachItemId = $this->partnerSuggestions[$reportId]['item']->id ?? null;
        $this->resetValidation();
        unset($this->attachItemOptions, $this->attachReportOptions);
        $this->showAttachModal = true;
    }

    /**
     * Open the attach form for an outsourced test, with its likely partner report chosen.
     */
    public function openAttachForItem(int $itemId): void
    {
        abort_unless($this->canManageResults, 403);

        $this->attachItemId = $this->filteredQuery()->findOrFail($itemId)->id;
        $this->attachReportId = null;
        unset($this->attachItemOptions, $this->attachReportOptions);

        $best = $this->attachReportOptions->first();
        $this->attachReportId = $best && $best['score'] >= PartnerLabMatcher::LIKELY_SCORE ? $best['report']->id : null;
        unset($this->attachItemOptions);

        $this->resetValidation();
        $this->showAttachModal = true;
    }

    /**
     * Refresh the ranked tests when a different report is picked in the form.
     */
    public function updatedAttachReportId(): void
    {
        unset($this->attachItemOptions);
    }

    /**
     * Download the chosen partner report and attach it to the chosen outsourced test.
     */
    public function attachPartnerReport(): void
    {
        abort_unless($this->canManageResults, 403);

        $this->validate([
            'attachReportId' => ['required', 'integer', Rule::exists('partner_lab_reports', 'id')],
            'attachItemId' => ['required', 'integer', Rule::exists('lab_invoice_items', 'id')->where('is_in_house', 0)],
        ], [], ['attachReportId' => __('partner report'), 'attachItemId' => __('outsourced test')]);

        $report = PartnerLabReport::query()->findOrFail($this->attachReportId);
        $item = LabInvoiceItem::query()->with('labInvoice')->findOrFail($this->attachItemId);

        try {
            app(AttachPartnerLabReport::class)->handle(auth()->user(), $report, $item);
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('attachReportId', $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : __('Could not download the report from the partner lab. Try again, or upload it by hand.'));

            return;
        }

        $this->showAttachModal = false;
        $this->reset('attachReportId', 'attachItemId');
        unset($this->items, $this->counts, $this->partnerReports, $this->partnerSuggestions, $this->waitingPartnerCount);

        Flux::toast(variant: 'success', text: __('Partner report attached to :test for :patient.', [
            'test' => trim($item->test_name),
            'patient' => $item->labInvoice?->patient?->name ?? __('the patient'),
        ]));
    }

    /**
     * Hide a partner report that does not belong to any of our tests.
     */
    public function ignorePartnerReport(int $reportId): void
    {
        abort_unless($this->canManageResults, 403);

        PartnerLabReport::query()->whereKey($reportId)->whereNull('lab_invoice_item_id')->update([
            'ignored_at' => now(),
            'ignored_by' => auth()->id(),
        ]);

        unset($this->partnerReports, $this->partnerSuggestions, $this->waitingPartnerCount);

        Flux::toast(text: __('Partner report hidden.'));
    }

    /**
     * Get the outsourced tests on the selected tab, oldest first while open, newest first otherwise.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, LabInvoiceItem>
     */
    #[Computed]
    public function items()
    {
        $query = $this->applyTab($this->filteredQuery(), $this->tab)
            ->with(['labInvoice.patient.family', 'labTest.fields', 'reportUploadedByUser']);

        return (in_array($this->tab, ['received', 'all'], true) ? $query->latest() : $query->oldest())
            ->paginate(25);
    }

    /**
     * Count the tests on every tab for the current date range and search.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return collect(self::TABS)
            ->mapWithKeys(fn (string $tab) => [$tab => $this->applyTab($this->filteredQuery(), $tab)->count()])
            ->all();
    }

    /**
     * Outsourced tests in the date range matching the search, on cases that were not returned.
     *
     * @return Builder<LabInvoiceItem>
     */
    private function filteredQuery(): Builder
    {
        [$from, $to] = $this->dateRange();

        return LabInvoiceItem::query()
            ->where('is_in_house', false)
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->when(filled($this->search), function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(function ($query) use ($term) {
                    $query->where('test_name', 'like', $term)
                        ->orWhereHas('labInvoice', function ($invoice) use ($term) {
                            $invoice->where('invoice_number', 'like', $term)
                                ->orWhereHas('patient', function ($patient) use ($term) {
                                    $patient->where('name', 'like', $term)
                                        ->orWhere('mrn', 'like', $term)
                                        ->orWhereHas('family', fn ($family) => $family->where('phone', 'like', $term));
                                });
                        });
                });
            });
    }

    /**
     * Narrow the query to one status tab.
     *
     * @param  Builder<LabInvoiceItem>  $query
     * @return Builder<LabInvoiceItem>
     */
    private function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'open' => $query->pending(),
            'reception' => $query->pending()->where(fn ($status) => $status
                ->whereNull('outgoing_status')
                ->orWhereIn('outgoing_status', [OutgoingSampleStatus::Pending->value, OutgoingSampleStatus::Asked->value])),
            'partner_lab' => $query->pending()->where('outgoing_status', OutgoingSampleStatus::Given->value),
            'late' => $query->lateAtPartnerLab(),
            'received' => $query->where(fn ($done) => $done
                ->where('outgoing_status', OutgoingSampleStatus::Received->value)
                ->orWhereNotNull('report_path')),
            default => $query,
        };
    }

    /**
     * Parse the date filter into start/end timestamps, ignoring invalid input.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function dateRange(): array
    {
        $parse = function (string $value, bool $endOfDay): ?CarbonImmutable {
            if (blank($value)) {
                return null;
            }

            try {
                $date = CarbonImmutable::parse($value);
            } catch (\Throwable) {
                return null;
            }

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        };

        return [$parse($this->fromDate, false), $parse($this->toDate, true)];
    }

    /**
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'fromDate', 'toDate', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Whether the current user may upload reports and enter results (the "Lab Results Entry" permission).
     */
    #[Computed]
    public function canManageResults(): bool
    {
        return auth()->user()?->canAccessRoute('lab.results.entry') ?? false;
    }

    /**
     * Describe where a test stands, for the status badge.
     *
     * @return array{label: string, color: string}
     */
    public function itemStatus(LabInvoiceItem $item): array
    {
        if ($item->isLegacy() && $item->results_completed_at === null) {
            return ['label' => __('Done in old lab software'), 'color' => 'zinc'];
        }

        if ($item->results_completed_at !== null) {
            return ['label' => __('Results complete'), 'color' => 'green'];
        }

        if (filled($item->report_path)) {
            return ['label' => __('Report uploaded'), 'color' => 'green'];
        }

        $status = $item->outgoing_status ?? OutgoingSampleStatus::Pending;

        if ($status === OutgoingSampleStatus::Given && $this->isLate($item)) {
            return ['label' => __('Late at partner lab'), 'color' => 'red'];
        }

        return [
            'label' => $status->label(),
            'color' => match ($status) {
                OutgoingSampleStatus::Pending => 'amber',
                OutgoingSampleStatus::Asked => 'orange',
                OutgoingSampleStatus::Given => 'purple',
                OutgoingSampleStatus::Received => 'green',
            },
        ];
    }

    /**
     * Whether a test has been with the partner lab longer than expected.
     */
    public function isLate(LabInvoiceItem $item): bool
    {
        return ! $item->isDone()
            && $item->outgoing_status === OutgoingSampleStatus::Given
            && ($item->given_at ?? $item->created_at)->lt(now()->subDays(CeoLabOverview::PARTNER_LAB_DAYS));
    }

    /**
     * Open the upload form for a test.
     */
    public function openUpload(int $itemId): void
    {
        abort_unless($this->canManageResults, 403);

        $item = $this->filteredQuery()->find($itemId);

        if (! $item) {
            Flux::toast(variant: 'danger', text: __('A report cannot be uploaded for this test.'));

            return;
        }

        $this->uploadItemId = $item->id;
        $this->reset('reportUpload');
        $this->resetValidation();
        $this->showUploadModal = true;
    }

    /**
     * Store the partner lab's report for the open test and mark its result received.
     */
    public function uploadReport(): void
    {
        abort_unless($this->canManageResults, 403);

        $item = $this->uploadItemId
            ? LabInvoiceItem::query()->where('is_in_house', false)->with('labInvoice')->find($this->uploadItemId)
            : null;

        if (! $item || $item->labInvoice?->isReturned()) {
            Flux::toast(variant: 'danger', text: __('A report cannot be uploaded for this test.'));

            return;
        }

        $this->validate(['reportUpload' => StorePartnerLabReport::RULES], [], ['reportUpload' => __('report')]);

        app(StorePartnerLabReport::class)->handle(auth()->user(), $item, $this->reportUpload);

        $this->showUploadModal = false;
        $this->uploadItemId = null;
        $this->reset('reportUpload');
        unset($this->items, $this->counts);

        Flux::toast(variant: 'success', text: __('Report uploaded.'));
    }

    /**
     * Delete the uploaded partner lab report for a test.
     */
    public function removeReport(int $itemId): void
    {
        abort_unless($this->canManageResults, 403);

        $item = LabInvoiceItem::query()->where('is_in_house', false)->find($itemId);

        if (! $item || blank($item->report_path)) {
            return;
        }

        app(StorePartnerLabReport::class)->remove($item);
        unset($this->items, $this->counts);

        Flux::toast(variant: 'success', text: __('Report removed.'));
    }
}; ?>

<div>
    @php
        $tabLabels = [
            'open' => __('Open'),
            'reception' => __('At reception'),
            'partner_lab' => __('With partner lab'),
            'late' => __('Late'),
            'received' => __('Received'),
            'all' => __('All'),
        ];
        $counts = $this->counts;
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Outsourced Tests') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Tests sent to the partner lab: track the sample, then upload the report or type in the results when it comes back. Late after :days days with the partner lab.', ['days' => CeoLabOverview::PARTNER_LAB_DAYS]) }}
                </flux:text>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($tabLabels as $key => $label)
                <flux:button
                    size="sm"
                    wire:key="tab-{{ $key }}"
                    wire:click="$set('tab', '{{ $key }}')"
                    :variant="$tab === $key ? 'primary' : 'filled'"
                >
                    {{ $label }}
                    <flux:badge size="sm" :color="$key === 'late' && $counts[$key] > 0 ? 'red' : 'zinc'" class="ms-1">{{ $counts[$key] }}</flux:badge>
                </flux:button>
            @endforeach
            <flux:button
                size="sm"
                icon="inbox-arrow-down"
                wire:key="tab-partner"
                wire:click="$set('tab', 'partner')"
                :variant="$tab === 'partner' ? 'primary' : 'filled'"
            >
                {{ __('Partner reports') }}
                <flux:badge size="sm" :color="$this->waitingPartnerCount > 0 ? 'sky' : 'zinc'" class="ms-1">{{ $this->waitingPartnerCount }}</flux:badge>
            </flux:button>
        </div>

        @if ($tab === 'partner')
            <flux:card>
                <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <flux:text class="text-sm">
                        {{ __('Reports that are ready on the Test Zone portal and not attached to one of our tests yet. Check the suggested match, then attach.') }}
                    </flux:text>
                    <flux:text class="text-xs text-zinc-500">
                        {{ $this->partnerLastSeenAt ? __('Portal last checked :time', ['time' => $this->partnerLastSeenAt->diffForHumans()]) : __('Portal not checked yet') }}
                    </flux:text>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Partner patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Test') }}</flux:table.column>
                        <flux:table.column>{{ __('Registered') }}</flux:table.column>
                        <flux:table.column>{{ __('Suggested match') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->partnerReports as $report)
                            @php $suggestion = $this->partnerSuggestions[$report->id] ?? null; @endphp
                            <flux:table.row wire:key="partner-report-{{ $report->id }}">
                                <flux:table.cell>
                                    <div class="font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $report->patient_name ?? __('Unknown') }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ collect([$report->patient_age, $report->patient_gender])->filter()->implode(' · ') ?: '—' }}
                                        · <span class="font-mono">{{ $report->partner_case_no }}</span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $report->test_name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $report->status }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    {{ $report->registered_at?->format('d M, g:i A') ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($suggestion)
                                        @php $suggestedPatient = $suggestion['item']->labInvoice?->patient; @endphp
                                        <div class="font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $suggestedPatient?->name ?? __('Unknown') }}</div>
                                        <div class="text-xs text-zinc-500">
                                            {{ trim($suggestion['item']->test_name) }}
                                            · <span class="font-mono">{{ $suggestion['item']->labInvoice?->invoice_number }}</span>
                                            · {{ $suggestion['item']->created_at->format('d M') }}
                                        </div>
                                        <flux:badge size="sm" class="mt-1" :color="$suggestion['score'] >= 85 ? 'green' : 'amber'">
                                            {{ __(':score% match', ['score' => $suggestion['score']]) }}
                                        </flux:badge>
                                    @else
                                        <span class="text-sm text-zinc-400">{{ __('No likely match') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="$report->report_url" target="_blank" rel="noopener noreferrer">
                                            {{ __('View') }}
                                        </flux:button>
                                        @if ($this->canManageResults)
                                            <flux:button size="sm" :variant="$suggestion ? 'primary' : 'filled'" icon="paper-clip" wire:click="openAttachForReport({{ $report->id }})">
                                                {{ __('Attach') }}
                                            </flux:button>
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="eye-slash"
                                                :tooltip="__('Not one of our tests: hide it')"
                                                wire:click="ignorePartnerReport({{ $report->id }})"
                                                wire:confirm="{{ __('Hide this partner report? Use this only if it is not one of our tests.') }}"
                                            />
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">
                                    {{ __('No partner reports waiting.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @else
        <flux:card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-[repeat(2,minmax(0,11rem))_minmax(0,1fr)] lg:items-end">
                <flux:input type="date" wire:model.live="fromDate" :label="__('From')" />
                <flux:input type="date" wire:model.live="toDate" :label="__('To')" />
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    :label="__('Search')"
                    icon="magnifying-glass"
                    placeholder="{{ __('Name, phone, MR, receipt no or test...') }}"
                />
            </div>
        </flux:card>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Test') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Billed') }}</flux:table.column>
                    <flux:table.column>{{ __('Sent') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->items as $item)
                        @php
                            $case = $item->labInvoice;
                            $patient = $case?->patient;
                            $status = $this->itemStatus($item);
                            $hasFile = filled($item->report_path);
                            $hasResults = $item->results_completed_at !== null;
                            $canEnterResults = $this->canManageResults && ($item->labTest?->fields->isNotEmpty() ?? false);
                        @endphp
                        <flux:table.row wire:key="outsourced-item-{{ $item->id }}">
                            <flux:table.cell>
                                <div class="font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $patient?->name ?? __('Unknown') }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ $patient?->contactPhone() ?? __('No phone') }}
                                    · <a href="{{ route('lab.cases.show', $case) }}" wire:navigate class="font-mono hover:underline">{{ $case->invoice_number }}</a>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ trim($item->test_name) }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ $item->sample ?: __('No sample type') }}
                                    @if ($item->time_required)
                                        · {{ $item->time_required }}
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                                @if ($item->report_uploaded_at)
                                    <div class="mt-1 text-xs text-zinc-500">
                                        {{ __('Uploaded :date by :name', ['date' => $item->report_uploaded_at->format('d M, g:i A'), 'name' => $item->reportUploadedByUser?->name ?? __('unknown')]) }}
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $item->created_at->format('d M Y') }}
                                <div class="text-xs text-zinc-500">{{ $item->created_at->format('g:i A') }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                @if ($item->given_at)
                                    {{ $item->given_at->format('d M, g:i A') }}
                                    @unless ($item->isDone())
                                        <div @class(['text-xs', 'font-medium text-red-600 dark:text-red-400' => $this->isLate($item), 'text-zinc-500' => ! $this->isLate($item)])>
                                            {{ __(':time with partner lab', ['time' => $item->given_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, parts: 2)]) }}
                                        </div>
                                    @endunless
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($hasFile)
                                        <flux:button.group>
                                            <flux:button
                                                size="sm"
                                                icon="paper-clip"
                                                :href="route('lab.cases.report-file', ['labInvoice' => $case, 'item' => $item])"
                                                target="_blank"
                                            >
                                                {{ __('Partner report') }}
                                            </flux:button>
                                            @if ($this->canManageResults)
                                                <flux:button
                                                    size="sm"
                                                    icon="trash"
                                                    :tooltip="__('Remove uploaded report')"
                                                    wire:click="removeReport({{ $item->id }})"
                                                    wire:confirm="{{ __('Remove the uploaded report for this test?') }}"
                                                />
                                            @endif
                                        </flux:button.group>
                                    @endif

                                    @if ($hasResults)
                                        <flux:button
                                            size="sm"
                                            icon="document-text"
                                            :href="route('lab.cases.report', ['labInvoice' => $case, 'item' => $item->id])"
                                            target="_blank"
                                        >
                                            {{ __('Show report') }}
                                        </flux:button>
                                    @endif

                                    @if ($this->canManageResults)
                                        <flux:button
                                            size="sm"
                                            :variant="$hasFile || $hasResults ? 'ghost' : 'primary'"
                                            icon="arrow-up-tray"
                                            wire:click="openUpload({{ $item->id }})"
                                        >
                                            {{ $hasFile ? __('Replace') : __('Upload report') }}
                                        </flux:button>
                                    @endif

                                    @if ($canEnterResults)
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            :icon="$item->results_completed_at ? 'pencil-square' : 'plus'"
                                            :href="route('lab.cases.show', ['labInvoice' => $case, 'results' => $item->id])"
                                            wire:navigate
                                        >
                                            {{ $item->results_completed_at ? __('Edit results') : __('Add results') }}
                                        </flux:button>
                                    @endif

                                    @if ($this->canManageResults && ! $hasFile && ! $hasResults && $this->waitingPartnerCount > 0)
                                        <flux:button size="sm" variant="ghost" icon="inbox-arrow-down" wire:click="openAttachForItem({{ $item->id }})">
                                            {{ __('Attach partner report') }}
                                        </flux:button>
                                    @endif

                                    @if (! $this->canManageResults && ! $hasFile && ! $hasResults)
                                        <flux:button size="sm" variant="ghost" :href="route('lab.cases.show', $case)" wire:navigate>{{ __('Open case') }}</flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                {{ __('No outsourced tests here.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->items->links() }}
            </div>
        </flux:card>
        @endif
    </div>

    <flux:modal wire:model="showAttachModal" class="w-full max-w-2xl">
        <flux:heading level="2">{{ __('Attach partner lab report') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('The report is downloaded from Test Zone and stored with the test, which is marked as result received. Best matches are listed first.') }}</flux:text>

        @if ($showAttachModal)
        <form wire:submit="attachPartnerReport" class="mt-6 space-y-4">
            <flux:select wire:model.live="attachReportId" :label="__('Partner report')">
                <flux:select.option value="">{{ __('Choose a report...') }}</flux:select.option>
                @foreach ($this->attachReportOptions as $option)
                    @php $optionReport = $option['report']; @endphp
                    <flux:select.option value="{{ $optionReport->id }}">
                        {{ $optionReport->patient_name }} ({{ collect([$optionReport->patient_age, $optionReport->patient_gender])->filter()->implode(', ') ?: '—' }}) · {{ $optionReport->test_name }} · {{ $optionReport->registered_at?->format('d M') }} · {{ $optionReport->partner_case_no }}{{ $option['score'] !== null ? ' · '.$option['score'].'%' : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="attachReportId" />

            <flux:select wire:model="attachItemId" :label="__('Our outsourced test')">
                <flux:select.option value="">{{ __('Choose a test...') }}</flux:select.option>
                @foreach ($this->attachItemOptions as $option)
                    @php $optionItem = $option['item']; @endphp
                    @php $optionPatient = $optionItem->labInvoice?->patient; @endphp
                    <flux:select.option value="{{ $optionItem->id }}">
                        {{ $optionPatient?->name ?? __('Unknown') }} ({{ collect([$optionPatient?->age, $optionPatient?->gender])->filter(fn ($value) => $value !== null && $value !== '')->implode(', ') ?: '—' }}) · {{ trim($optionItem->test_name) }} · {{ $optionItem->labInvoice?->invoice_number }} · {{ $optionItem->created_at->format('d M') }}{{ filled($optionItem->report_path) ? ' · '.__('has a report') : '' }}{{ $option['score'] !== null ? ' · '.$option['score'].'%' : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="attachItemId" />

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showAttachModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" icon="paper-clip" wire:loading.attr="disabled" wire:target="attachPartnerReport">{{ __('Attach report') }}</flux:button>
            </div>
        </form>
        @endif
    </flux:modal>

    <flux:modal wire:model="showUploadModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Upload partner lab report') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Attach the PDF or a photo of the report. The test is marked as result received.') }}</flux:text>

        <form wire:submit="uploadReport" class="mt-6 space-y-4">
            <flux:field>
                <flux:input type="file" wire:model="reportUpload" accept="application/pdf,.pdf,image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" />
                <flux:error name="reportUpload" />
                <div wire:loading wire:target="reportUpload" class="mt-1 text-sm text-zinc-500">{{ __('Uploading...') }}</div>
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showUploadModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="uploadReport,reportUpload">{{ __('Upload') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
