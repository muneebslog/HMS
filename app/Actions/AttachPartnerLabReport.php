<?php

namespace App\Actions;

use App\Models\LabInvoiceItem;
use App\Models\PartnerLabReport;
use App\Models\User;
use App\Services\PartnerLab\TestZoneClient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AttachPartnerLabReport
{
    public function __construct(
        private TestZoneClient $client,
        private StorePartnerLabReport $storeReport,
    ) {}

    /**
     * Download a partner lab report and attach it to one of our outsourced tests,
     * marking the result received. Any report attached to that test before is released.
     *
     * @throws InvalidArgumentException when the report is not ready or the test cannot take a report
     * @throws \RuntimeException when the partner lab does not return the PDF
     */
    public function handle(User $user, PartnerLabReport $report, LabInvoiceItem $item): void
    {
        if (! $report->isReady()) {
            throw new InvalidArgumentException(__('This partner report is not ready yet.'));
        }

        if ($item->is_in_house) {
            throw new InvalidArgumentException(__('Partner reports can only be attached to outsourced tests.'));
        }

        $pdf = $this->client->downloadReport($report->report_url);
        $name = str($report->partner_case_no.' '.$report->test_name)->slug()->append('.pdf')->toString();

        $this->storeReport->handleContents($user, $item, $pdf, $name);

        DB::transaction(function () use ($user, $report, $item) {
            PartnerLabReport::query()
                ->where('lab_invoice_item_id', $item->id)
                ->whereKeyNot($report->id)
                ->update(['lab_invoice_item_id' => null, 'attached_at' => null, 'attached_by' => null]);

            $report->update([
                'lab_invoice_item_id' => $item->id,
                'attached_at' => now(),
                'attached_by' => $user->id,
                'ignored_at' => null,
                'ignored_by' => null,
            ]);
        });
    }
}
