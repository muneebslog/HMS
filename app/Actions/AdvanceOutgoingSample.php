<?php

namespace App\Actions;

use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoiceItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class AdvanceOutgoingSample
{
    /**
     * Advance an outgoing lab invoice item to the next workflow status.
     */
    public function handle(
        LabInvoiceItem $item,
        OutgoingSampleStatus $target,
        User $actor,
        ?UploadedFile $report = null,
    ): LabInvoiceItem {
        return DB::transaction(function () use ($item, $target, $actor, $report): LabInvoiceItem {
            /** @var LabInvoiceItem $locked */
            $locked = LabInvoiceItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->is_in_house || $locked->outgoing_status === null) {
                throw new InvalidArgumentException(__('Only outgoing samples can be advanced.'));
            }

            if (! $locked->outgoing_status->canAdvanceTo($target)) {
                throw new InvalidArgumentException(__('Invalid outgoing sample status transition.'));
            }

            $attributes = [
                'outgoing_status' => $target,
            ];

            if ($target === OutgoingSampleStatus::Asked) {
                $attributes['asked_at'] = now();
                $attributes['asked_by'] = $actor->id;
            }

            if ($target === OutgoingSampleStatus::Given) {
                $attributes['given_at'] = now();
                $attributes['given_by'] = $actor->id;
            }

            if ($target === OutgoingSampleStatus::Received) {
                $attributes['received_at'] = now();
                $attributes['received_by'] = $actor->id;
            }

            if ($report !== null) {
                if ($target !== OutgoingSampleStatus::Received && $locked->outgoing_status !== OutgoingSampleStatus::Received) {
                    throw new InvalidArgumentException(__('Reports can only be uploaded when marking received or after received.'));
                }

                $attributes = array_merge($attributes, $this->storeReport($locked, $report, $actor));
            }

            $locked->update($attributes);

            return $locked->refresh();
        });
    }

    /**
     * Store or replace the outgoing report PDF for an item that is already received.
     */
    public function storeReportForItem(LabInvoiceItem $item, UploadedFile $report, User $actor): LabInvoiceItem
    {
        if ($item->is_in_house || $item->outgoing_status !== OutgoingSampleStatus::Received) {
            throw new InvalidArgumentException(__('Reports can only be uploaded for received outgoing samples.'));
        }

        $item->update($this->storeReport($item, $report, $actor));

        return $item->refresh();
    }

    /**
     * @return array{report_path: string, report_original_name: string, report_uploaded_at: Carbon, report_uploaded_by: int}
     */
    private function storeReport(LabInvoiceItem $item, UploadedFile $report, User $actor): array
    {
        if (filled($item->report_path) && Storage::disk('local')->exists($item->report_path)) {
            Storage::disk('local')->delete($item->report_path);
        }

        $directory = 'lab-reports/'.$item->lab_invoice_id;
        $path = $report->storeAs(
            $directory,
            $item->id.'-'.now()->format('YmdHis').'.pdf',
            'local',
        );

        return [
            'report_path' => $path,
            'report_original_name' => $report->getClientOriginalName(),
            'report_uploaded_at' => now(),
            'report_uploaded_by' => $actor->id,
        ];
    }
}
