<?php

namespace App\Actions;

use App\Models\LabInvoiceItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        if ($item->is_in_house || $item->labInvoice?->isReturned()) {
            throw new InvalidArgumentException(__('A report cannot be uploaded for this test.'));
        }

        $previousPath = $item->report_path;
        $path = $file->store("lab-reports/{$item->lab_invoice_id}", 'local');

        DB::transaction(function () use ($user, $item, $file, $path) {
            $item->update([
                'report_path' => $path,
                'report_original_name' => $file->getClientOriginalName(),
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
        });

        Storage::disk('local')->delete($path);
    }
}
