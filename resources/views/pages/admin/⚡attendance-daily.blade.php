<?php

use App\Enums\AttendanceRecordStatus;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceRecord;
use App\Services\AttendanceProcessingService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Daily Attendance Review')] class extends Component
{
    public string $date;

    public ?int $editingRecordId = null;

    public ?string $firstInAt = null;

    public ?string $lastOutAt = null;

    public string $overrideReason = '';

    public function mount(?string $date = null): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }

        $this->date = $date ?? today()->toDateString();
    }

    #[Computed]
    public function records()
    {
        return AttendanceRecord::query()
            ->with(['healthAide', 'dutyAssignment.shiftTemplate'])
            ->whereDate('date', $this->date)
            ->orderBy('scheduled_starts_at')
            ->get();
    }

    public function reprocess(AttendanceProcessingService $processingService): void
    {
        foreach ($this->records as $record) {
            if ($record->dutyAssignment !== null) {
                $processingService->processAssignment($record->dutyAssignment);
            }
        }

        unset($this->records);
        Flux::toast(variant: 'success', text: __('Attendance reprocessed.'));
    }

    public function editRecord(int $id): void
    {
        $record = AttendanceRecord::query()->findOrFail($id);
        $this->editingRecordId = $record->id;
        $this->firstInAt = $record->first_in_at?->format('Y-m-d\TH:i');
        $this->lastOutAt = $record->last_out_at?->format('Y-m-d\TH:i');
        $this->overrideReason = '';
    }

    public function saveOverride(AttendanceProcessingService $processingService): void
    {
        $validated = $this->validate([
            'firstInAt' => ['nullable', 'date'],
            'lastOutAt' => ['nullable', 'date', 'after:firstInAt'],
            'overrideReason' => ['required', 'string', 'max:1000'],
        ]);

        $record = AttendanceRecord::query()->with('dutyAssignment')->findOrFail($this->editingRecordId);

        if ($record->first_in_at?->toDateTimeString() !== $validated['firstInAt']) {
            AttendanceAdjustment::query()->create([
                'attendance_record_id' => $record->id,
                'field_changed' => 'first_in_at',
                'old_value' => $record->first_in_at?->toDateTimeString(),
                'new_value' => $validated['firstInAt'],
                'reason' => $validated['overrideReason'],
                'created_by' => auth()->id(),
            ]);
        }

        if ($record->last_out_at?->toDateTimeString() !== $validated['lastOutAt']) {
            AttendanceAdjustment::query()->create([
                'attendance_record_id' => $record->id,
                'field_changed' => 'last_out_at',
                'old_value' => $record->last_out_at?->toDateTimeString(),
                'new_value' => $validated['lastOutAt'],
                'reason' => $validated['overrideReason'],
                'created_by' => auth()->id(),
            ]);
        }

        $record->update([
            'first_in_at' => $validated['firstInAt'],
            'last_out_at' => $validated['lastOutAt'],
            'is_manual_override' => true,
            'override_reason' => $validated['overrideReason'],
            'overridden_by' => auth()->id(),
        ]);

        if ($record->dutyAssignment !== null && $record->first_in_at !== null && $record->last_out_at !== null) {
            $metrics = $processingService->calculateMetrics(
                $record->dutyAssignment,
                $record->first_in_at,
                $record->last_out_at,
            );

            $record->update([
                'worked_minutes' => $metrics['worked_minutes'],
                'late_minutes' => $metrics['late_minutes'],
                'early_leave_minutes' => $metrics['early_leave_minutes'],
                'overtime_minutes' => $metrics['overtime_minutes'],
                'payable_minutes' => $metrics['payable_minutes'],
                'status' => $metrics['status'],
            ]);
        }

        $this->editingRecordId = null;
        unset($this->records);
        Flux::toast(variant: 'success', text: __('Attendance record updated.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Daily Attendance Review') }}</flux:heading>
        <div class="flex gap-2">
            <flux:input wire:model.live="date" type="date" />
            <flux:button wire:click="reprocess" icon="arrow-path">{{ __('Reprocess') }}</flux:button>
        </div>
    </div>

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Aide') }}</flux:table.column>
                <flux:table.column>{{ __('Scheduled') }}</flux:table.column>
                <flux:table.column>{{ __('In / Out') }}</flux:table.column>
                <flux:table.column>{{ __('Payable') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->records as $record)
                    <flux:table.row wire:key="record-{{ $record->id }}">
                        <flux:table.cell>{{ $record->healthAide->name }}</flux:table.cell>
                        <flux:table.cell>{{ $record->scheduled_starts_at?->format('H:i') }} - {{ $record->scheduled_ends_at?->format('H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $record->first_in_at?->format('H:i') ?? '—' }} / {{ $record->last_out_at?->format('H:i') ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($record->payable_minutes / 60, 2) }}h</flux:table.cell>
                        <flux:table.cell><flux:badge :color="$record->status->badgeColor()">{{ $record->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell><flux:button size="sm" wire:click="editRecord({{ $record->id }})">{{ __('Edit') }}</flux:button></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="6">{{ __('No attendance records for this date.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    @if ($editingRecordId)
        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Manual Override') }}</flux:heading>
            <form wire:submit="saveOverride" class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="firstInAt" type="datetime-local" label="{{ __('Check In') }}" />
                <flux:input wire:model="lastOutAt" type="datetime-local" label="{{ __('Check Out') }}" />
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="overrideReason" label="{{ __('Reason') }}" required />
                </div>
                <div class="sm:col-span-2 flex gap-2">
                    <flux:button type="submit" variant="primary">{{ __('Save Override') }}</flux:button>
                    <flux:button type="button" wire:click="$set('editingRecordId', null)">{{ __('Cancel') }}</flux:button>
                </div>
            </form>
        </flux:card>
    @endif
</div>
