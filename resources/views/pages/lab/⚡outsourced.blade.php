<?php

use App\Actions\StorePartnerLabReport;
use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoiceItem;
use App\Services\CeoLabOverview;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Default the date filter to the window outsourced tests are tracked for.
     */
    public function mount(): void
    {
        $this->fromDate = now()->subDays(CeoLabOverview::OUTSOURCED_OPEN_DAYS - 1)->toDateString();
        $this->toDate = now()->toDateString();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'open';
        }
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
        </div>

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
    </div>

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
