<?php

namespace App\Services;

use App\Enums\WardMaintenanceFaultPriority;
use App\Enums\WardMaintenanceShift;
use App\Enums\WardMaintenanceStatus;
use App\Models\User;
use App\Models\WardMaintenanceAnswer;
use App\Models\WardMaintenanceEntry;
use App\Models\WardMaintenanceFault;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WardMaintenanceService
{
    public function __construct(
        private WardMaintenanceChecklistDefinition $definition,
        private NotificationService $notifications,
    ) {}

    /**
     * Find an existing entry for the given date and shift.
     */
    public function findEntry(Carbon|string $date, WardMaintenanceShift $shift): ?WardMaintenanceEntry
    {
        return WardMaintenanceEntry::query()
            ->whereDate('checklist_date', $date)
            ->where('shift', $shift)
            ->first();
    }

    /**
     * Build a flat status map keyed by "section|item|location".
     *
     * @return array<string, string>
     */
    public function emptyStatusMap(): array
    {
        $map = [];

        foreach ($this->definition->statusCells() as $cell) {
            $map[$this->statusKey($cell['section'], $cell['item_key'], $cell['location_key'])] = '';
        }

        return $map;
    }

    /**
     * Build an empty equipment map.
     *
     * @return array<string, array{available: bool|null, functional: bool|null, remarks: string}>
     */
    public function emptyEquipmentMap(): array
    {
        $map = [];

        foreach ($this->definition->sectionEItems() as $itemKey => $label) {
            $map[$itemKey] = [
                'available' => null,
                'functional' => null,
                'remarks' => '',
            ];
        }

        return $map;
    }

    /**
     * Compose a stable status key.
     */
    public function statusKey(string $section, string $itemKey, string $locationKey = ''): string
    {
        return "{$section}|{$itemKey}|{$locationKey}";
    }

    /**
     * Persist a completed checklist and notify admins when faults exist.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, string>  $statuses
     * @param  array<string, array{available: bool|null, functional: bool|null, remarks: string}>  $equipment
     * @param  list<array<string, mixed>>  $faultRows
     */
    public function submit(
        User $nurse,
        Carbon $date,
        WardMaintenanceShift $shift,
        array $header,
        array $statuses,
        array $equipment,
        array $faultRows,
    ): WardMaintenanceEntry {
        if ($this->findEntry($date, $shift) !== null) {
            throw ValidationException::withMessages([
                'shift' => __('This shift checklist has already been submitted.'),
            ]);
        }

        $entry = DB::transaction(function () use ($nurse, $date, $shift, $header, $statuses, $equipment, $faultRows): WardMaintenanceEntry {
            $entry = WardMaintenanceEntry::create([
                'user_id' => $nurse->id,
                'checklist_date' => $date->toDateString(),
                'shift' => $shift,
                'checked_by_name' => $header['checked_by_name'],
                'supervisor_name' => $header['supervisor_name'] ?: null,
                'checked_by_time' => $header['checked_by_time'] ?: null,
                'supervisor_time' => $header['supervisor_time'] ?: null,
                'patient_safety_fault' => $header['patient_safety_fault'],
                'patient_safety_reported' => $header['patient_safety_reported'],
                'room_unavailable' => $header['room_unavailable'],
                'beds_out_of_service' => $header['beds_out_of_service'] ?: null,
                'reason_remarks' => $header['reason_remarks'] ?: null,
                'supervisor_remarks' => $header['supervisor_remarks'] ?: null,
                'submitted_at' => now(),
            ]);

            foreach ($this->definition->statusCells() as $cell) {
                $key = $this->statusKey($cell['section'], $cell['item_key'], $cell['location_key']);
                $status = WardMaintenanceStatus::from($statuses[$key]);

                WardMaintenanceAnswer::create([
                    'entry_id' => $entry->id,
                    'section' => $cell['section'],
                    'item_key' => $cell['item_key'],
                    'location_key' => $cell['location_key'],
                    'status' => $status,
                ]);
            }

            foreach ($this->definition->sectionEItems() as $itemKey => $label) {
                $row = $equipment[$itemKey];

                WardMaintenanceAnswer::create([
                    'entry_id' => $entry->id,
                    'section' => 'E',
                    'item_key' => $itemKey,
                    'location_key' => '',
                    'available' => (bool) $row['available'],
                    'functional' => (bool) $row['functional'],
                    'remarks' => $row['remarks'] !== '' ? $row['remarks'] : null,
                ]);
            }

            foreach ($faultRows as $index => $row) {
                if ($this->faultRowIsEmpty($row)) {
                    continue;
                }

                WardMaintenanceFault::create([
                    'entry_id' => $entry->id,
                    'fault_time' => $row['fault_time'] ?: null,
                    'bed_room' => $row['bed_room'] ?: null,
                    'description' => $row['description'] ?: null,
                    'priority' => ! empty($row['priority'])
                        ? WardMaintenanceFaultPriority::from($row['priority'])
                        : null,
                    'reported_to' => $row['reported_to'] ?: null,
                    'action_taken' => $row['action_taken'] ?: null,
                    'resolved' => array_key_exists('resolved', $row) && $row['resolved'] !== null && $row['resolved'] !== ''
                        ? filter_var($row['resolved'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                        : null,
                    'sort_order' => $index,
                ]);
            }

            return $entry;
        });

        $entry->load(['answers', 'faults', 'user']);

        if ($entry->hasFaults()) {
            $this->notifications->notifyWardMaintenanceFaults($nurse, $entry);
        }

        return $entry;
    }

    /**
     * Determine whether a fault report row has no meaningful content.
     *
     * @param  array<string, mixed>  $row
     */
    public function faultRowIsEmpty(array $row): bool
    {
        return blank($row['fault_time'] ?? null)
            && blank($row['bed_room'] ?? null)
            && blank($row['description'] ?? null)
            && blank($row['priority'] ?? null)
            && blank($row['reported_to'] ?? null)
            && blank($row['action_taken'] ?? null)
            && ($row['resolved'] ?? null) === null;
    }
}
