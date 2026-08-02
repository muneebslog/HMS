<?php

use App\Enums\PrintJobStatus;
use App\Models\PdfPrintJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('PDF Print')] class extends Component
{
    use WithFileUploads;

    public string $statusFilter = 'all';

    public int $perPage = 20;

    public ?TemporaryUploadedFile $pdf = null;

    /**
     * Get the filtered PDF print jobs.
     *
     * @return Collection<int, PdfPrintJob>
     */
    #[Computed]
    public function jobs(): Collection
    {
        $query = PdfPrintJob::with('user')
            ->latest();

        if ($this->statusFilter !== 'all' && in_array($this->statusFilter, PrintJobStatus::values(), true)) {
            $query->where('status', $this->statusFilter);
        }

        return $query->limit($this->perPage)->get();
    }

    /**
     * Queue an uploaded PDF for printing.
     */
    public function queuePrint(): void
    {
        $this->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        /** @var TemporaryUploadedFile $pdf */
        $pdf = $this->pdf;

        $path = $pdf->store('pdf-print-jobs', 'local');

        PdfPrintJob::create([
            'user_id' => Auth::id(),
            'original_filename' => $pdf->getClientOriginalName(),
            'disk_path' => $path,
            'status' => PrintJobStatus::Pending,
        ]);

        $this->reset('pdf');

        unset($this->jobs);

        session()->flash('status', __('PDF queued for printing.'));
    }

    /**
     * Reset a failed PDF print job back to pending.
     */
    public function retry(int $jobId): void
    {
        $job = PdfPrintJob::find($jobId);

        if ($job === null) {
            return;
        }

        if (! Storage::disk('local')->exists($job->disk_path)) {
            session()->flash('error', __('PDF file is missing and cannot be retried.'));

            return;
        }

        $job->update([
            'status' => PrintJobStatus::Pending,
            'failed_at' => null,
            'error_message' => null,
        ]);

        unset($this->jobs);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('PDF Print') }}</flux:heading>
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle">
                {{ session('status') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="x-circle">
                {{ session('error') }}
            </flux:callout>
        @endif

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Upload PDF') }}</flux:heading>

            <form wire:submit="queuePrint" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="w-full flex-1">
                    <flux:field>
                        <flux:label>{{ __('PDF file') }}</flux:label>
                        <flux:input type="file" wire:model="pdf" accept="application/pdf,.pdf" />
                        <flux:error name="pdf" />
                    </flux:field>
                </div>

                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="queuePrint,pdf">{{ __('Queue print') }}</span>
                    <span wire:loading wire:target="queuePrint,pdf">{{ __('Uploading...') }}</span>
                </flux:button>
            </form>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Recent Jobs') }}</flux:heading>

                <flux:select wire:model.live="statusFilter" class="w-full sm:w-auto">
                    <option value="all">{{ __('All statuses') }}</option>
                    @foreach (App\Enums\PrintJobStatus::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </flux:select>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ID') }}</flux:table.column>
                    <flux:table.column>{{ __('Filename') }}</flux:table.column>
                    <flux:table.column>{{ __('Uploaded by') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Attempts') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->jobs as $job)
                        <flux:table.row wire:key="pdf-print-job-{{ $job->id }}">
                            <flux:table.cell>#{{ $job->id }}</flux:table.cell>
                            <flux:table.cell>{{ $job->original_filename }}</flux:table.cell>
                            <flux:table.cell>{{ $job->user?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($job->status === App\Enums\PrintJobStatus::Pending)
                                    <flux:badge size="sm" color="amber">{{ $job->status->label() }}</flux:badge>
                                @elseif ($job->status === App\Enums\PrintJobStatus::Printed)
                                    <flux:badge size="sm" color="green">{{ $job->status->label() }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red" title="{{ $job->error_message }}">{{ $job->status->label() }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $job->attempts }}</flux:table.cell>
                            <flux:table.cell>{{ $job->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                @if ($job->status === App\Enums\PrintJobStatus::Failed)
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-path"
                                        wire:click="retry({{ $job->id }})"
                                    />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="text-center text-zinc-500">
                                {{ __('No PDF print jobs found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
</div>
