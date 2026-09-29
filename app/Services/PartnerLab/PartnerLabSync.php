<?php

namespace App\Services\PartnerLab;

use App\Models\PartnerLabReport;
use Carbon\CarbonImmutable;

/**
 * Copies the tests on the partner lab's portal into partner_lab_reports so the lab
 * can attach ready reports to our outsourced tests. Reports are never attached
 * automatically, and PDFs are only downloaded when someone attaches one.
 */
class PartnerLabSync
{
    public function __construct(
        private TestZoneClient $client,
        private TestZonePageParser $parser,
    ) {}

    /**
     * Look through cases registered in the last few days and record their tests.
     *
     * @return array{cases: int, expanded: int, new: int, ready: int}
     */
    public function run(int $days): array
    {
        $to = CarbonImmutable::today();
        $from = $to->subDays(max(0, $days - 1));
        $searchPage = $this->client->searchCases($from, $to);
        $cases = $this->parser->cases($searchPage);
        $summary = ['cases' => count($cases), 'expanded' => 0, 'new' => 0, 'ready' => 0];

        foreach ($cases as $case) {
            if ($this->isFinished($case['case_no'])) {
                continue;
            }

            $tests = $this->parser->tests($this->client->expandCase($searchPage, $case['event_target']));
            $summary['expanded']++;

            foreach ($tests as $test) {
                $result = $this->store($case, $test);
                $summary['new'] += $result['new'] ? 1 : 0;
                $summary['ready'] += $result['became_ready'] ? 1 : 0;
            }
        }

        return $summary;
    }

    /**
     * Whether every known test of a case is already ready, so it need not be opened again.
     */
    private function isFinished(string $caseNo): bool
    {
        $reports = PartnerLabReport::query()
            ->where('source', PartnerLabReport::SOURCE_TEST_ZONE)
            ->where('partner_case_no', $caseNo)
            ->get(['ready_at']);

        return $reports->isNotEmpty() && $reports->every(fn (PartnerLabReport $report) => $report->ready_at !== null);
    }

    /**
     * Record one test, keeping when its report first became ready.
     *
     * @param  array{case_no: string, patient_no: ?string, patient_name: ?string, age: ?string, gender: ?string, registered_at: ?CarbonImmutable, reference: ?string}  $case
     * @param  array{partner_test_id: string, code: ?string, name: ?string, status: ?string, report_path: ?string}  $test
     * @return array{new: bool, became_ready: bool}
     */
    private function store(array $case, array $test): array
    {
        $report = PartnerLabReport::query()->firstOrNew([
            'source' => PartnerLabReport::SOURCE_TEST_ZONE,
            'partner_test_id' => $test['partner_test_id'],
        ]);

        $isNew = ! $report->exists;
        $isReady = PartnerLabReport::isReadyStatus($test['status']) && filled($test['report_path']);
        $becameReady = $isReady && $report->ready_at === null;

        $report->fill([
            'partner_case_no' => $case['case_no'],
            'partner_patient_no' => $case['patient_no'],
            'patient_name' => $case['patient_name'],
            'patient_age' => $case['age'],
            'patient_gender' => $case['gender'],
            'registered_at' => $case['registered_at'],
            'reference' => $case['reference'],
            'test_code' => $test['code'],
            'test_name' => $test['name'],
            'status' => $test['status'],
            'report_url' => filled($test['report_path']) ? $this->client->absoluteUrl($test['report_path']) : $report->report_url,
            'ready_at' => $becameReady ? now() : $report->ready_at,
            'last_seen_at' => now(),
        ])->save();

        return ['new' => $isNew, 'became_ready' => $becameReady];
    }
}
