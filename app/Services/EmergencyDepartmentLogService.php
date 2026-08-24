<?php

namespace App\Services;

use App\Enums\EmergencyDepartmentEquipmentStatus;
use App\Enums\EmergencyDepartmentShift;
use App\Models\EmergencyDepartmentLogAnswer;
use App\Models\EmergencyDepartmentLogEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmergencyDepartmentLogService
{
    public function __construct(
        private EmergencyDepartmentLogDefinition $definition,
        private NotificationService $notifications,
    ) {}

    /**
     * Find an existing entry for the given date and shift.
     */
    public function findEntry(Carbon|string $date, EmergencyDepartmentShift $shift): ?EmergencyDepartmentLogEntry
    {
        return EmergencyDepartmentLogEntry::query()
            ->whereDate('checklist_date', $date)
            ->where('shift', $shift)
            ->first();
    }

    /**
     * Build an empty handover map.
     *
     * @return array<string, array{count: string, remarks: string}>
     */
    public function emptyHandoverMap(): array
    {
        $map = [];

        foreach (array_keys($this->definition->handoverMetrics()) as $itemKey) {
            $map[$itemKey] = [
                'count' => '',
                'remarks' => '',
            ];
        }

        return $map;
    }

    /**
     * Build an empty equipment map.
     *
     * @return array<string, array{status: string, remarks: string}>
     */
    public function emptyEquipmentMap(): array
    {
        $map = [];

        foreach (array_keys($this->definition->equipmentItems()) as $itemKey) {
            $map[$itemKey] = [
                'status' => '',
                'remarks' => '',
            ];
        }

        return $map;
    }

    /**
     * Build an empty crash-cart map.
     *
     * @return array<string, array{adequate: bool|null, remarks: string}>
     */
    public function emptyCrashCartMap(): array
    {
        $map = [];

        foreach ($this->definition->crashCartItems() as $item) {
            $map[$item['item_key']] = [
                'adequate' => null,
                'remarks' => '',
            ];
        }

        return $map;
    }

    /**
     * Build an empty cleaning map keyed by "section|item".
     *
     * @return array<string, bool|null>
     */
    public function emptyCleaningMap(): array
    {
        $map = [];

        foreach ($this->definition->cleaningItems() as $item) {
            $map[$this->cleaningKey($item['section'], $item['item_key'])] = null;
        }

        return $map;
    }

    /**
     * Compose a stable cleaning key.
     */
    public function cleaningKey(string $section, string $itemKey): string
    {
        return "{$section}|{$itemKey}";
    }

    /**
     * Persist a completed operational log and notify admins when faults exist.
     *
     * @param  array{completed_by_name: string, supervisor_name: string, equipment_issues_log: string}  $header
     * @param  array<string, array{count: int|string, remarks: string}>  $handover
     * @param  array<string, array{status: string, remarks: string}>  $equipment
     * @param  array<string, array{adequate: bool|null, remarks: string}>  $crashCart
     * @param  array<string, bool|null>  $cleaning
     */
    public function submit(
        User $nurse,
        Carbon $date,
        EmergencyDepartmentShift $shift,
        array $header,
        array $handover,
        array $equipment,
        array $crashCart,
        array $cleaning,
    ): EmergencyDepartmentLogEntry {
        if ($this->findEntry($date, $shift) !== null) {
            throw ValidationException::withMessages([
                'shift' => __('This shift log has already been submitted.'),
            ]);
        }

        $entry = DB::transaction(function () use ($nurse, $date, $shift, $header, $handover, $equipment, $crashCart, $cleaning): EmergencyDepartmentLogEntry {
            $entry = EmergencyDepartmentLogEntry::create([
                'user_id' => $nurse->id,
                'checklist_date' => $date->toDateString(),
                'shift' => $shift,
                'completed_by_name' => $header['completed_by_name'],
                'supervisor_name' => $header['supervisor_name'] !== '' ? $header['supervisor_name'] : null,
                'equipment_issues_log' => $header['equipment_issues_log'] !== '' ? $header['equipment_issues_log'] : null,
                'submitted_at' => now(),
            ]);

            foreach ($this->definition->handoverMetrics() as $itemKey => $label) {
                $row = $handover[$itemKey];

                EmergencyDepartmentLogAnswer::create([
                    'entry_id' => $entry->id,
                    'section' => 'A',
                    'item_key' => $itemKey,
                    'count' => (int) $row['count'],
                    'remarks' => $row['remarks'] !== '' ? $row['remarks'] : null,
                ]);
            }

            foreach ($this->definition->equipmentItems() as $itemKey => $item) {
                $row = $equipment[$itemKey];

                EmergencyDepartmentLogAnswer::create([
                    'entry_id' => $entry->id,
                    'section' => 'B',
                    'item_key' => $itemKey,
                    'status' => EmergencyDepartmentEquipmentStatus::from($row['status']),
                    'remarks' => $row['remarks'] !== '' ? $row['remarks'] : null,
                ]);
            }

            foreach ($this->definition->crashCartItems() as $item) {
                $row = $crashCart[$item['item_key']];

                EmergencyDepartmentLogAnswer::create([
                    'entry_id' => $entry->id,
                    'section' => $item['section'],
                    'item_key' => $item['item_key'],
                    'adequate' => $this->toBool($row['adequate']),
                    'remarks' => $row['remarks'] !== '' ? $row['remarks'] : null,
                ]);
            }

            foreach ($this->definition->cleaningItems() as $item) {
                $key = $this->cleaningKey($item['section'], $item['item_key']);

                EmergencyDepartmentLogAnswer::create([
                    'entry_id' => $entry->id,
                    'section' => $item['section'],
                    'item_key' => $item['item_key'],
                    'checked' => $this->toBool($cleaning[$key]),
                ]);
            }

            return $entry;
        });

        $entry->load(['answers', 'user']);

        if ($entry->hasFaults()) {
            $this->notifications->notifyEmergencyDepartmentLogFaults($nurse, $entry);
        }

        return $entry;
    }

    /**
     * Cast a Livewire / form boolean without treating "0" as true.
     */
    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
