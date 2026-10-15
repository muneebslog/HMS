<?php

namespace App\Services\Hermes;

use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Services\CeoLabOverview;
use Carbon\CarbonImmutable;

/**
 * Live lab status for Hermes, built on the same stages as the CEO lab overview.
 */
class LabStatusReport
{
    public function __construct(private CeoLabOverview $overview) {}

    /**
     * @return array{
     *     as_of: string,
     *     today: array{tests_today: int, done_today: int, in_lab: int, with_rider: int, retakes: int, late: int},
     *     stages: list<array{key: string, label: string, held_by: string, count: int, oldest_wait_minutes: ?int}>,
     *     samples_to_send_out: list<array<string, mixed>>,
     *     retakes: list<array<string, mixed>>,
     *     late_tests: list<array<string, mixed>>
     * }
     */
    public function build(int $detailLimit = 15): array
    {
        $stages = $this->overview->stages();
        $stagesByKey = collect($stages)->keyBy('key');

        $toSendOut = collect(['rider_not_called', 'waiting_rider'])
            ->flatMap(fn (string $key) => $stagesByKey[$key]['items']->map(fn (LabInvoiceItem $item) => [
                ...$this->itemSummary($item),
                'status' => $stagesByKey[$key]['label'],
                'waiting_minutes' => (int) $item->created_at->diffInMinutes(now()),
            ]))
            ->sortByDesc('waiting_minutes')
            ->take($detailLimit)
            ->values()
            ->all();

        return [
            'as_of' => now()->toDateTimeString(),
            'today' => $this->overview->todayStrip(),
            'stages' => collect($stages)->map(fn (array $stage) => [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'held_by' => $stage['owner'],
                'count' => $stage['count'],
                'oldest_wait_minutes' => $stage['oldest_minutes'],
            ])->values()->all(),
            'samples_to_send_out' => $toSendOut,
            'retakes' => $this->openRetakes($detailLimit),
            'late_tests' => $this->overview->lateTests()
                ->take($detailLimit)
                ->map(fn (array $late) => [
                    ...$this->itemSummary($late['item']),
                    'stage' => $late['item']->stage,
                    'reason' => $late['reason'],
                    'late_minutes' => $late['late_minutes'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Retakes still waiting for the patient to come back, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function openRetakes(int $limit): array
    {
        return LabSampleRetake::query()
            ->open()
            ->where('created_at', '>=', CarbonImmutable::today()->subDays(CeoLabOverview::IN_HOUSE_OPEN_DAYS - 1))
            ->with('labInvoiceItem.labInvoice.patient')
            ->oldest()
            ->limit($limit)
            ->get()
            ->map(fn (LabSampleRetake $retake) => [
                ...$this->itemSummary($retake->labInvoiceItem),
                'reason' => $retake->reason,
                'requested_at' => $retake->created_at->toDateTimeString(),
                'patient_contacted' => $retake->patient_contacted_at !== null,
                'patient_contacted_at' => $retake->patient_contacted_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{test: string, case: ?string, patient_mrn: ?string, billed_at: string}
     */
    private function itemSummary(LabInvoiceItem $item): array
    {
        return [
            'test' => $item->test_name,
            'case' => $item->labInvoice?->invoice_number,
            'patient_mrn' => PatientReference::of($item->labInvoice?->patient),
            'billed_at' => $item->created_at->toDateTimeString(),
        ];
    }
}
