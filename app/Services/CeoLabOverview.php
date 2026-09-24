<?php

namespace App\Services;

use App\Enums\OutgoingSampleStatus;
use App\Enums\PaymentMode;
use App\Models\Doctor;
use App\Models\HealthAide;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the CEO's lab overview shows: where every open test is and who holds it,
 * what is late, money, speed, people and trends.
 */
class CeoLabOverview
{
    /**
     * Days an outsourced sample may stay with the partner lab before it counts as late.
     */
    public const PARTNER_LAB_DAYS = 2;

    /**
     * How far back open in-house tests are tracked (older ones were never completed in the HMS).
     */
    public const IN_HOUSE_OPEN_DAYS = 7;

    /**
     * How far back open outsourced tests are tracked.
     */
    public const OUTSOURCED_OPEN_DAYS = 30;

    /**
     * The periods the page can switch between.
     *
     * @var array<string, string>
     */
    public const PERIODS = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days'];

    /**
     * @var Collection<int, LabInvoiceItem>|null
     */
    private ?Collection $openItems = null;

    /**
     * Get the first day of a period.
     */
    public function periodStart(string $period): CarbonImmutable
    {
        return match ($period) {
            '7d' => CarbonImmutable::today()->subDays(6),
            '30d' => CarbonImmutable::today()->subDays(29),
            default => CarbonImmutable::today(),
        };
    }

    /**
     * Every unfinished test being tracked, each tagged with its stage.
     *
     * @return Collection<int, LabInvoiceItem>
     */
    public function openItems(): Collection
    {
        return $this->openItems ??= LabInvoiceItem::query()
            ->pending()
            ->where(fn ($query) => $query
                ->where(fn ($inHouse) => $inHouse->where('is_in_house', true)->where('created_at', '>=', CarbonImmutable::today()->subDays(self::IN_HOUSE_OPEN_DAYS - 1)))
                ->orWhere(fn ($outsourced) => $outsourced->where('is_in_house', false)->whereNotNull('outgoing_status')->where('created_at', '>=', CarbonImmutable::today()->subDays(self::OUTSOURCED_OPEN_DAYS - 1))))
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))
            ->with(['labInvoice.patient', 'latestRetake'])
            ->withCount('results')
            ->oldest()
            ->get()
            ->each(fn (LabInvoiceItem $item) => $item->setAttribute('stage', $this->stageOf($item)));
    }

    /**
     * Which stage an unfinished test is in.
     */
    public function stageOf(LabInvoiceItem $item): string
    {
        if ($item->is_in_house) {
            return match (true) {
                $item->hasOpenRetake() => 'retake',
                $item->sample_received_at === null && $item->results_count === 0 => 'not_received',
                default => 'results_pending',
            };
        }

        return match ($item->outgoing_status) {
            OutgoingSampleStatus::Asked => 'waiting_rider',
            OutgoingSampleStatus::Given => 'partner_lab',
            default => 'rider_not_called',
        };
    }

    /**
     * The board: each stage, who holds it, how many tests and the oldest wait.
     *
     * @return list<array{key: string, label: string, owner: string, color: string, count: int, oldest_minutes: ?int, items: Collection<int, LabInvoiceItem>}>
     */
    public function stages(): array
    {
        $definitions = [
            'rider_not_called' => [__('Rider not called'), __('Reception'), 'amber'],
            'waiting_rider' => [__('Waiting for rider'), __('Reception'), 'orange'],
            'partner_lab' => [__('With partner lab'), __('Partner lab'), 'purple'],
            'not_received' => [__('Sample not in lab'), __('Lab'), 'sky'],
            'retake' => [__('Retake: patient to return'), __('Reception'), 'rose'],
            'results_pending' => [__('Results pending'), __('Lab technician'), 'violet'],
        ];

        $grouped = $this->openItems()->groupBy('stage');

        return collect($definitions)->map(function (array $definition, string $key) use ($grouped) {
            $items = $grouped->get($key, collect());
            $clockStart = fn (LabInvoiceItem $item) => $key === 'partner_lab' ? ($item->given_at ?? $item->created_at) : $item->created_at;

            return [
                'key' => $key,
                'label' => $definition[0],
                'owner' => $definition[1],
                'color' => $definition[2],
                'count' => $items->count(),
                'oldest_minutes' => $items->isEmpty() ? null : (int) $items->map(fn ($item) => $clockStart($item)->diffInMinutes(now()))->max(),
                'items' => $items->values(),
            ];
        })->values()->all();
    }

    /**
     * Tests past their promised time, or with the partner lab too long; most late first.
     *
     * @return Collection<int, array{item: LabInvoiceItem, reason: string, late_minutes: int}>
     */
    public function lateTests(): Collection
    {
        return $this->openItems()
            ->map(function (LabInvoiceItem $item) {
                if ($item->stage === 'partner_lab') {
                    $deadline = CarbonImmutable::parse($item->given_at ?? $item->created_at)->addDays(self::PARTNER_LAB_DAYS);
                    $reason = __('over :days days', ['days' => self::PARTNER_LAB_DAYS]);
                } else {
                    $deadline = $item->dueAt();
                    $reason = __('Promised :time', ['time' => $item->time_required ?: __('same day')]);
                }

                return now()->greaterThan($deadline)
                    ? ['item' => $item, 'reason' => $reason, 'late_minutes' => (int) $deadline->diffInMinutes(now())]
                    : null;
            })
            ->filter()
            ->sortByDesc('late_minutes')
            ->values();
    }

    /**
     * The one-line summary for today.
     *
     * @return array{tests_today: int, done_today: int, in_lab: int, with_rider: int, retakes: int, late: int}
     */
    public function todayStrip(): array
    {
        $open = $this->openItems()->countBy('stage');

        return [
            'tests_today' => LabInvoiceItem::query()->whereDate('created_at', today())->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))->count(),
            'done_today' => LabInvoiceItem::query()->where(fn ($query) => $query->whereDate('results_completed_at', today())->orWhereDate('received_at', today()))->count(),
            'in_lab' => ($open['not_received'] ?? 0) + ($open['results_pending'] ?? 0),
            'with_rider' => ($open['rider_not_called'] ?? 0) + ($open['waiting_rider'] ?? 0) + ($open['partner_lab'] ?? 0),
            'retakes' => $open['retake'] ?? 0,
            'late' => $this->lateTests()->count(),
        ];
    }

    /**
     * Lab money for the period.
     *
     * @return array{revenue: float, cases: int, average: float, discounts: float, returns_count: int, returns_amount: float, doctor_shares: float, net: float, by_mode: array<string, float>, in_house: float, outsourced: float, top_doctors: Collection<int, array{name: string, cases: int, revenue: float, share: float}>}
     */
    public function money(CarbonImmutable $from): array
    {
        $invoices = LabInvoice::query()->where('created_at', '>=', $from)->with('items')->get();
        [$returned, $kept] = $invoices->partition(fn (LabInvoice $invoice) => $invoice->isReturned());

        $revenue = (float) $kept->sum('total');
        $doctorShares = (float) $kept->sum(fn (LabInvoice $invoice) => $invoice->doctorShareAmount());
        $items = $kept->flatMap->items;

        $doctorNames = Doctor::query()->whereIn('id', $kept->pluck('referred_by_doctor_id')->filter()->unique())->pluck('name', 'id');

        return [
            'revenue' => $revenue,
            'cases' => $kept->count(),
            'average' => $kept->isEmpty() ? 0.0 : $revenue / $kept->count(),
            'discounts' => (float) $kept->sum('discount_amount'),
            'returns_count' => $returned->count(),
            'returns_amount' => (float) $returned->sum('total'),
            'doctor_shares' => $doctorShares,
            'net' => $revenue - $doctorShares,
            'by_mode' => collect(PaymentMode::cases())
                ->mapWithKeys(fn (PaymentMode $mode) => [$mode->label() => (float) $kept->filter(fn ($invoice) => $invoice->payment_mode === $mode)->sum('total')])
                ->all(),
            'in_house' => (float) $items->where('is_in_house', true)->sum('price'),
            'outsourced' => (float) $items->where('is_in_house', false)->sum('price'),
            'top_doctors' => $kept->whereNotNull('referred_by_doctor_id')
                ->groupBy('referred_by_doctor_id')
                ->map(fn (Collection $cases, int $doctorId) => [
                    'name' => $doctorNames[$doctorId] ?? __('Unknown'),
                    'cases' => $cases->count(),
                    'revenue' => (float) $cases->sum('total'),
                    'share' => (float) $cases->sum(fn (LabInvoice $invoice) => $invoice->doctorShareAmount()),
                ])
                ->sortByDesc('revenue')
                ->take(5)
                ->values(),
        ];
    }

    /**
     * Turnaround and sample quality for the period.
     *
     * @return array{median_minutes: ?int, week_median_minutes: ?int, on_time_rate: ?int, completed: int, retakes: int, retake_rate: ?float, retake_reasons: Collection<string, int>}
     */
    public function speed(CarbonImmutable $from): array
    {
        $completed = fn (CarbonImmutable $since) => LabInvoiceItem::query()
            ->where('is_in_house', true)
            ->whereNull('results_imported_at')
            ->where('results_completed_at', '>=', $since)
            ->get();

        $median = function (Collection $items): ?int {
            $minutes = $items->map(fn (LabInvoiceItem $item) => (int) $item->created_at->diffInMinutes($item->results_completed_at));

            return $minutes->isEmpty() ? null : (int) $minutes->median();
        };

        $periodCompleted = $completed($from);
        $retakes = LabSampleRetake::query()->where('created_at', '>=', $from)->get();
        $inHouseBilled = LabInvoiceItem::query()->where('is_in_house', true)->where('created_at', '>=', $from)->count();

        return [
            'median_minutes' => $median($periodCompleted),
            'week_median_minutes' => $median($completed(CarbonImmutable::today()->subDays(6))),
            'on_time_rate' => $periodCompleted->isEmpty() ? null : (int) round($periodCompleted->filter(fn (LabInvoiceItem $item) => $item->results_completed_at->lessThanOrEqualTo($item->dueAt()))->count() / $periodCompleted->count() * 100),
            'completed' => $periodCompleted->count(),
            'retakes' => $retakes->count(),
            'retake_rate' => $inHouseBilled > 0 ? round($retakes->count() / $inHouseBilled * 100, 1) : null,
            'retake_reasons' => $retakes->countBy('reason')->sortDesc()->take(4),
        ];
    }

    /**
     * What each person did in the period.
     *
     * @return array{lab: Collection<int, array{name: string, completed: int, received: int}>, reception: Collection<int, array{name: string, handed: int, retakes: int}>}
     */
    public function people(CarbonImmutable $from): array
    {
        $completed = LabInvoiceItem::query()->where('results_completed_at', '>=', $from)->whereNotNull('results_completed_by')->pluck('results_completed_by')->countBy();
        $received = LabInvoiceItem::query()->where('sample_received_at', '>=', $from)->whereNotNull('sample_received_by')->pluck('sample_received_by')->countBy();
        $receivedAtEr = LabInvoiceItem::query()->where('sample_collected_at', '>=', $from)->whereNotNull('sample_collected_by_health_aide_id')->pluck('sample_collected_by_health_aide_id')->countBy();
        $aideNames = HealthAide::query()->whereIn('id', $receivedAtEr->keys())->pluck('name', 'id');
        $handed = LabInvoiceItem::query()->where('given_at', '>=', $from)->whereNotNull('given_by')->pluck('given_by')->countBy();
        $retakeSlips = LabSampleRetake::query()->where('slip_printed_at', '>=', $from)->whereNotNull('slip_printed_by')->pluck('slip_printed_by')->countBy();

        $names = User::query()
            ->whereIn('id', $completed->keys()->merge($received->keys())->merge($handed->keys())->merge($retakeSlips->keys())->unique())
            ->pluck('name', 'id');

        return [
            'lab' => $completed->keys()->merge($received->keys())->unique()
                ->map(fn (int $userId) => ['name' => $names[$userId] ?? __('Unknown'), 'completed' => (int) ($completed[$userId] ?? 0), 'received' => (int) ($received[$userId] ?? 0)])
                ->merge($receivedAtEr->map(fn (int $count, int $aideId) => ['name' => __(':name (ER)', ['name' => $aideNames[$aideId] ?? __('Unknown')]), 'completed' => 0, 'received' => $count]))
                ->sortByDesc(fn (array $person) => [$person['completed'], $person['received']])
                ->values(),
            'reception' => $handed->keys()->merge($retakeSlips->keys())->unique()
                ->map(fn (int $userId) => ['name' => $names[$userId] ?? __('Unknown'), 'handed' => (int) ($handed[$userId] ?? 0), 'retakes' => (int) ($retakeSlips[$userId] ?? 0)])
                ->sortByDesc('handed')
                ->values(),
        ];
    }

    /**
     * Tests and revenue per day for the last 30 days, and the tests growing fastest this week.
     *
     * @return array{days: list<array{date: CarbonImmutable, tests: int, revenue: float}>, top_tests: Collection<int, array{name: string, this_week: int, last_week: int}>}
     */
    public function trends(): array
    {
        $from = CarbonImmutable::today()->subDays(29);
        $invoices = LabInvoice::query()
            ->where('created_at', '>=', CarbonImmutable::today()->subDays(29))
            ->where('status', '!=', 'returned')
            ->withCount('items')
            ->get();

        $days = collect(range(0, 29))->map(function (int $offset) use ($from, $invoices) {
            $date = $from->addDays($offset);
            $dayInvoices = $invoices->filter(fn (LabInvoice $invoice) => $invoice->created_at->isSameDay($date));

            return ['date' => $date, 'tests' => (int) $dayInvoices->sum('items_count'), 'revenue' => (float) $dayInvoices->sum('total')];
        })->all();

        $items = LabInvoiceItem::query()
            ->where('created_at', '>=', CarbonImmutable::today()->subDays(13))
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))
            ->get(['test_name', 'created_at']);
        $weekStart = CarbonImmutable::today()->subDays(6);

        $topTests = $items->groupBy(fn (LabInvoiceItem $item) => trim($item->test_name))
            ->map(fn (Collection $tests, string $name) => [
                'name' => $name,
                'this_week' => $tests->filter(fn ($item) => $item->created_at->greaterThanOrEqualTo($weekStart))->count(),
                'last_week' => $tests->filter(fn ($item) => $item->created_at->lessThan($weekStart))->count(),
            ])
            ->sortByDesc('this_week')
            ->take(6)
            ->values();

        return ['days' => $days, 'top_tests' => $topTests];
    }

    /**
     * Tests billed by weekday and hour over the last 30 days, for staffing.
     *
     * @return array{rows: list<array{label: string, hours: array<int, int>}>, hours: list<int>, max: int}
     */
    public function busyHours(): array
    {
        $items = LabInvoiceItem::query()->where('created_at', '>=', CarbonImmutable::today()->subDays(29))->pluck('created_at');
        $counts = $items->countBy(fn ($createdAt) => CarbonImmutable::parse($createdAt)->dayOfWeekIso.'-'.CarbonImmutable::parse($createdAt)->hour);
        $hours = range(8, 23);

        $rows = collect(range(1, 7))->map(fn (int $day) => [
            'label' => CarbonImmutable::today()->startOfWeek()->addDays($day - 1)->format('D'),
            'hours' => collect($hours)->mapWithKeys(fn (int $hour) => [$hour => (int) ($counts["{$day}-{$hour}"] ?? 0)])->all(),
        ])->all();

        return ['rows' => $rows, 'hours' => $hours, 'max' => max(1, (int) $counts->max())];
    }
}
