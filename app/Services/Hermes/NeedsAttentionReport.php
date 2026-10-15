<?php

namespace App\Services\Hermes;

use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\MedicationOrder;
use App\Models\Procedure;
use App\Models\Shift;
use App\Services\CeoLabOverview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Things the owner should follow up on, each with who to ask: late lab work, samples not taken,
 * missing prescriptions, undelivered medicines, unfinished procedure records and cash shortages.
 */
class NeedsAttentionReport
{
    /**
     * Minutes a billed in-house sample may wait before it counts as not taken.
     */
    public const SAMPLE_WAIT_MINUTES = 60;

    /**
     * Minutes an outsourced sample may wait for the rider.
     */
    public const RIDER_WAIT_MINUTES = 120;

    /**
     * Days on the ward after which an admission probably needs to be discharged in the HMS.
     */
    public const WARD_DAYS = 4;

    /**
     * Days back to look for discharged procedures with missing payment or birth certificate data.
     */
    public const DISCHARGE_LOOKBACK_DAYS = 30;

    private const DETAIL_LIMIT = 5;

    public function __construct(
        private CeoLabOverview $lab,
        private PrescriptionsReport $prescriptions,
        private ShiftSummaryReport $shifts,
        private ProceduresReport $procedures,
    ) {}

    /**
     * @return array{period: array<string, mixed>, headline: array<string, mixed>, issues: list<array{area: string, issue: string, ask: string, count: int, details: list<string>}>, message: string}
     */
    public function build(ReportPeriod $period): array
    {
        $prescriptionReport = $this->prescriptions->build($period, 0);
        $shiftReport = $this->shifts->build($period);

        $issues = collect([
            ...$this->labIssues(),
            ...$this->prescriptionIssues($period, $prescriptionReport),
            ...$this->procedureIssues(),
            ...$this->moneyIssues($shiftReport),
        ])->filter(fn (array $issue) => $issue['count'] > 0)->values()->all();

        $headline = [
            'checkup_patients' => $prescriptionReport['totals']['patients'],
            'prescriptions_entered' => $prescriptionReport['totals']['with_prescription'],
            'total_sales' => $shiftReport['totals']['money']['total_sales'],
            'expenses' => $shiftReport['totals']['money']['expenses'],
            'procedures_added' => $this->procedures->build($period, 0)['counts']['added'],
            'lab_tests_today' => $this->lab->todayStrip()['tests_today'],
        ];

        return [
            'period' => $period->toArray(),
            'headline' => $headline,
            'issues' => $issues,
            'message' => $this->message($period, $headline, $issues),
        ];
    }

    /**
     * @return list<array{area: string, issue: string, ask: string, count: int, details: list<string>}>
     */
    private function labIssues(): array
    {
        $stages = collect($this->lab->stages())->keyBy('key');
        $olderThan = fn (string $key, int $minutes) => $stages[$key]['items']
            ->filter(fn (LabInvoiceItem $item) => $item->created_at->lt(now()->subMinutes($minutes)));

        $notTaken = $olderThan('not_received', self::SAMPLE_WAIT_MINUTES);
        $riderWaiting = $olderThan('rider_not_called', self::RIDER_WAIT_MINUTES)->merge($olderThan('waiting_rider', self::RIDER_WAIT_MINUTES));
        $lateResults = $this->lab->lateTests()->filter(fn (array $late) => in_array($late['item']->stage, ['results_pending', 'partner_lab'], true));
        $retakes = LabSampleRetake::query()
            ->open()
            ->whereNull('patient_contacted_at')
            ->where('created_at', '>=', CarbonImmutable::today()->subDays(CeoLabOverview::IN_HOUSE_OPEN_DAYS - 1))
            ->with('labInvoiceItem.labInvoice.patient')
            ->oldest()
            ->get();

        return [
            $this->issue('Lab', 'Lab results late', 'Lab technician (in-house) / partner lab (outsourced)', $lateResults->map(
                fn (array $late) => sprintf('%s · %s · %s late%s', $late['item']->test_name, $this->mrn($late['item']), $this->duration($late['late_minutes']), $late['item']->stage === 'partner_lab' ? ' (partner lab)' : '')
            )),
            $this->issue('Lab', 'Samples billed but not taken / not in lab (over '.self::SAMPLE_WAIT_MINUTES.' min)', 'Lab / health aide on duty', $notTaken->map(
                fn (LabInvoiceItem $item) => sprintf('%s · %s · waiting %s', $item->test_name, $this->mrn($item), $this->duration((int) $item->created_at->diffInMinutes(now())))
            )),
            $this->issue('Lab', 'Outsourced samples not sent with the rider (over '.intdiv(self::RIDER_WAIT_MINUTES, 60).' h)', 'Reception', $riderWaiting->map(
                fn (LabInvoiceItem $item) => sprintf('%s · %s · %s', $item->test_name, $this->mrn($item), $item->stage === 'waiting_rider' ? 'rider called, not handed over' : 'rider not called')
            )),
            $this->issue('Lab', 'Retakes: patient not contacted yet', 'Reception', $retakes->map(
                fn (LabSampleRetake $retake) => sprintf('%s · %s · %s', $retake->labInvoiceItem->test_name, $this->mrn($retake->labInvoiceItem), $retake->reason)
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{area: string, issue: string, ask: string, count: int, details: list<string>}>
     */
    private function prescriptionIssues(ReportPeriod $period, array $report): array
    {
        $perShift = $period->shifts->map(function (Shift $shift) {
            $totals = $this->prescriptions->build(ReportPeriod::forShift($shift), 0)['totals'];

            return [
                'missing' => $totals['without_prescription'],
                'line' => sprintf('%s shift: %d of %d without prescription — entered by %s',
                    $shift->period()->label(),
                    $totals['without_prescription'],
                    $totals['patients'],
                    $this->prescribers($shift) ?: 'nobody'),
            ];
        })->filter(fn (array $row) => $row['missing'] > 0);

        $delivery = $report['medication_delivery'];
        $notGiven = $delivery['medicines']['left'] + $delivery['injections']['left'];

        return [
            [
                'area' => 'Prescriptions',
                'issue' => 'General checkup patients without a prescription in the HMS',
                'ask' => 'Doctor(s) on general checkup',
                'count' => (int) $report['totals']['without_prescription'],
                'details' => $perShift->pluck('line')->values()->all(),
            ],
            [
                'area' => 'Prescriptions',
                'issue' => 'Prescriptions entered after the shift closed',
                'ask' => 'Doctor(s) on general checkup',
                'count' => (int) $report['entered_after_shift_closed']['count'],
                'details' => [],
            ],
            [
                'area' => 'Prescriptions',
                'issue' => 'Medicines / injections prescribed but not marked given',
                'ask' => 'Nurses / health aides',
                'count' => $notGiven,
                'details' => [sprintf('%d medicines, %d injections', $delivery['medicines']['left'], $delivery['injections']['left'])],
            ],
            [
                'area' => 'Prescriptions',
                'issue' => 'Drip slips billed without a doctor\'s drip order',
                'ask' => 'Reception / doctor',
                'count' => (int) $delivery['drips']['without_order'],
                'details' => [],
            ],
        ];
    }

    /**
     * @return list<array{area: string, issue: string, ask: string, count: int, details: list<string>}>
     */
    private function procedureIssues(): array
    {
        $onWard = Procedure::query()->onWard()->with(['patient', 'procedureType'])->oldest('admitted_at')->get();
        $since = CarbonImmutable::today()->subDays(self::DISCHARGE_LOOKBACK_DAYS);
        $discharged = Procedure::query()
            ->where('discharged_at', '>=', $since)
            ->with(['patient', 'procedureType'])
            ->withSum('activePayments as paid_amount', 'amount')
            ->withExists('birthCertificateDetail')
            ->oldest('discharged_at')
            ->get();

        $longStays = $onWard->filter(fn (Procedure $procedure) => $procedure->admitted_at->lt(now()->subDays(self::WARD_DAYS)));
        $noFile = $onWard->whereNull('file_printed_at');
        $withDues = $discharged->filter(fn (Procedure $procedure) => $this->balance($procedure) > 0);
        $noBirthCertificate = $discharged->filter(fn (Procedure $procedure) => $procedure->procedureType?->requires_birth_certificate && ! $procedure->birth_certificate_detail_exists);

        return [
            $this->issue('Procedures', 'On the ward for more than '.self::WARD_DAYS.' days (discharge them in the HMS if they have left)', 'Indoor in-charge nurse / reception', $longStays->map(
                fn (Procedure $procedure) => sprintf('%s · %s · admitted %s', $this->procedureName($procedure), PatientReference::of($procedure->patient), $procedure->admitted_at->format('d M'))
            )),
            $this->issue('Procedures', 'Admitted but file not printed', 'Reception', $noFile->map(
                fn (Procedure $procedure) => sprintf('%s · %s · admitted %s', $this->procedureName($procedure), PatientReference::of($procedure->patient), $procedure->admitted_at->format('d M H:i'))
            )),
            $this->issue('Procedures', 'Discharged with payment still due (last '.self::DISCHARGE_LOOKBACK_DAYS.' days)', 'Reception', $withDues->map(
                fn (Procedure $procedure) => sprintf('%s · %s · Rs %s due', $this->procedureName($procedure), PatientReference::of($procedure->patient), number_format($this->balance($procedure)))
            ), sprintf('Rs %s total', number_format($withDues->sum(fn (Procedure $procedure) => $this->balance($procedure))))),
            $this->issue('Procedures', 'Deliveries discharged without birth certificate details (last '.self::DISCHARGE_LOOKBACK_DAYS.' days)', 'Reception', $noBirthCertificate->map(
                fn (Procedure $procedure) => sprintf('%s · %s · discharged %s', $this->procedureName($procedure), PatientReference::of($procedure->patient), $procedure->discharged_at->format('d M'))
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{area: string, issue: string, ask: string, count: int, details: list<string>}>
     */
    private function moneyIssues(array $report): array
    {
        $shifts = collect($report['shifts']);
        $short = $shifts->filter(fn (array $shift) => $shift['settlement']['is_short'] ?? false);
        $unsettled = $shifts->filter(fn (array $shift) => $shift['status'] === 'closed' && $shift['settlement'] === null);
        $pendingApprovals = (int) $shifts->sum('counts.pending_approvals');

        return [
            $this->issue('Cash', 'Shift cash short at settlement', 'Receptionist of that shift', $short->map(
                fn (array $shift) => sprintf('%s shift (%s): Rs %s short', $shift['period'], $shift['opened_by'] ?? '?', number_format(abs((float) $shift['settlement']['difference'])))
            )),
            $this->issue('Cash', 'Closed shifts not settled yet', 'Finance / admin', $unsettled->map(
                fn (array $shift) => sprintf('%s shift (%s), expected cash Rs %s', $shift['period'], $shift['opened_by'] ?? '?', number_format((float) $shift['money']['expected_cash']))
            )),
            [
                'area' => 'Cash',
                'issue' => 'Expenses / returns waiting for your approval',
                'ask' => 'Admin (you)',
                'count' => $pendingApprovals,
                'details' => [],
            ],
        ];
    }

    /**
     * @param  Collection<int, string>  $lines
     * @return array{area: string, issue: string, ask: string, count: int, details: list<string>}
     */
    private function issue(string $area, string $issue, string $ask, Collection $lines, ?string $summary = null): array
    {
        $details = $lines->values();

        return [
            'area' => $area,
            'issue' => $issue,
            'ask' => $ask,
            'count' => $details->count(),
            'details' => array_values(array_filter([$summary, ...$details->take(self::DETAIL_LIMIT)->all(), $details->count() > self::DETAIL_LIMIT ? '…and '.($details->count() - self::DETAIL_LIMIT).' more' : null])),
        ];
    }

    /**
     * Names of the staff who entered general checkup prescriptions in a shift, busiest first.
     */
    private function prescribers(Shift $shift): string
    {
        return MedicationOrder::query()
            ->whereHas('queueToken.serviceQueue', fn (Builder $queue) => $queue
                ->where('shift_id', $shift->id)
                ->whereHas('service', fn (Builder $service) => $service->where('needs_medication', true)))
            ->with('prescribedBy:id,name')
            ->get(['id', 'prescribed_by'])
            ->groupBy(fn (MedicationOrder $order) => $order->prescribedBy?->name ?? '?')
            ->map(fn (Collection $orders, string $name) => "{$name} ({$orders->count()})")
            ->sortDesc()
            ->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $headline
     * @param  list<array{area: string, issue: string, ask: string, count: int, details: list<string>}>  $issues
     */
    private function message(ReportPeriod $period, array $headline, array $issues): string
    {
        $lines = [
            '*HMS daily check* — '.$period->label,
            sprintf('Checkup patients %d (prescriptions %d) · Sales Rs %s · Expenses Rs %s · Procedures added %d · Lab tests today %d',
                $headline['checkup_patients'], $headline['prescriptions_entered'], number_format($headline['total_sales']),
                number_format($headline['expenses']), $headline['procedures_added'], $headline['lab_tests_today']),
            '',
        ];

        if ($issues === []) {
            $lines[] = '✅ Nothing needs your attention.';

            return implode("\n", $lines);
        }

        $lines[] = sprintf('⚠️ *%d things need attention*', count($issues));

        foreach (collect($issues)->groupBy('area') as $area => $areaIssues) {
            $lines[] = '';
            $lines[] = "*{$area}*";

            foreach ($areaIssues as $issue) {
                $lines[] = "• {$issue['issue']}: *{$issue['count']}* → ask {$issue['ask']}";

                foreach ($issue['details'] as $detail) {
                    $lines[] = "   - {$detail}";
                }
            }
        }

        $lines[] = '';
        $lines[] = '_Lab and ward items are live as of '.now()->format('d M H:i').'; patients shown by MR number._';

        return implode("\n", $lines);
    }

    private function mrn(LabInvoiceItem $item): string
    {
        return PatientReference::of($item->labInvoice?->patient) ?? '?';
    }

    private function balance(Procedure $procedure): float
    {
        return (float) $procedure->full_amount - (float) ($procedure->paid_amount ?? 0);
    }

    private function procedureName(Procedure $procedure): string
    {
        return $procedure->procedureType?->name ?? $procedure->name ?? 'Procedure';
    }

    private function duration(int $minutes): string
    {
        return match (true) {
            $minutes >= 1440 => intdiv($minutes, 1440).'d '.intdiv($minutes % 1440, 60).'h',
            $minutes >= 60 => intdiv($minutes, 60).'h '.($minutes % 60).'m',
            default => $minutes.'m',
        };
    }
}
