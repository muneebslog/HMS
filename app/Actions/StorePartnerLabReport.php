<?php

namespace App\Actions;

use App\Models\LabInvoiceItem;
use App\Models\PartnerLabReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StorePartnerLabReport
{
    /**
     * Allowed report files: a PDF or a photo, up to 20 MB.
     *
     * @var list<string>
     */
    public const RULES = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'];

    /**
     * Store the partner lab's report for a send-out test and mark its result received.
     * A new upload replaces (and deletes) the previous file.
     */
    public function handle(User $user, LabInvoiceItem $item, UploadedFile $file): void
    {
        $this->ensureUploadable($item);

        $this->save($user, $item, $file->store("lab-reports/{$item->lab_invoice_id}", 'local'), $file->getClientOriginalName());
    }

    /**
     * Store a report PDF fetched from the partner lab (rather than uploaded) the same way.
     */
    public function handleContents(User $user, LabInvoiceItem $item, string $contents, string $originalName): void
    {
        $this->ensureUploadable($item);

        $path = "lab-reports/{$item->lab_invoice_id}/".Str::random(40).'.pdf';
        Storage::disk('local')->put($path, $contents);

        $this->save($user, $item, $path, $originalName);
    }

    /**
     * @throws InvalidArgumentException when the test is in-house or its case was returned
     */
    private function ensureUploadable(LabInvoiceItem $item): void
    {
        if ($item->is_in_house || $item->labInvoice?->isReturned()) {
            throw new InvalidArgumentException(__('A report cannot be uploaded for this test.'));
        }
    }

    /**
     * Point the test at its stored report, mark the result received and drop the previous file.
     */
    private function save(User $user, LabInvoiceItem $item, string $path, string $originalName): void
    {
        $previousPath = $item->report_path;

        DB::transaction(function () use ($user, $item, $path, $originalName) {
            $item->update([
                'report_path' => $path,
                'report_original_name' => $originalName,
                'report_uploaded_at' => now(),
                'report_uploaded_by' => $user->id,
            ]);

            $item->markOutgoingReceived($user->id);
        });

        if (filled($previousPath) && $previousPath !== $path) {
            Storage::disk('local')->delete($previousPath);
        }
    }

    /**
     * Delete the uploaded report for a send-out test, putting it back with the partner lab
     * unless its results were completed in the HMS.
     */
    public function remove(LabInvoiceItem $item): void
    {
        if ($item->is_in_house || blank($item->report_path)) {
            return;
        }

        $path = $item->report_path;

        DB::transaction(function () use ($item) {
            $item->update([
                'report_path' => null,
                'report_original_name' => null,
                'report_uploaded_at' => null,
                'report_uploaded_by' => null,
            ]);

            $item->reopenOutgoing();

            PartnerLabReport::query()
                ->where('lab_invoice_item_id', $item->id)
                ->update(['lab_invoice_item_id' => null, 'attached_at' => null, 'attached_by' => null]);
        });

        Storage::disk('local')->delete($path);
    }
}
