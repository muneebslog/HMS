<?php

namespace App\Services;

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Models\EquipmentInspectionAnswer;
use App\Models\EquipmentInspectionEntry;
use App\Models\EquipmentInspectionRegisterRow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipmentInspectionService
{
    public function __construct(
        private EquipmentInspectionChecklistDefinition $definition,
        private NotificationService $notifications,
    ) {}

    /**
     * Find an existing entry for the given area, date, and shift.
     */
    public function findEntry(
        EquipmentInspectionArea $area,
        Carbon|string $date,
        EquipmentInspectionShift $shift,
    ): ?EquipmentInspectionEntry {
        return EquipmentInspectionEntry::query()
            ->where('area', $area)
            ->whereDate('checklist_date', $date)
            ->where('shift', $shift)
            ->first();
    }

    /**
     * Build an empty equipment map for section A.
     *
     * @return array<string, array{present: bool|null, functional: bool|null, clean: bool|null, maint_req: bool|null, remarks: string}>
     */
    public function emptyEquipmentMap(EquipmentInspectionArea $area): array
    {
        $map = [];

        foreach ($this->definition->equipmentItems($area) as $itemKey => $item) {
            $map[$itemKey] = [
                'present' => null,
                'functional' => null,
                'clean' => null,
                'maint_req' => null,
                'remarks' => '',
            ];
        }

        return $map;
    }

    /**
     * Build an empty checklist map keyed by "section|item".
     *
     * @return array<string, bool|null>
     */
    public function emptyChecklistMap(EquipmentInspectionArea $area): array
    {
        $map = [];

        foreach ($this->definition->checklistSections($area) as $section) {
            foreach ($this->definition->checklistItems($area, $section) as $itemKey => $label) {
                $map[$this->checklistKey($section, $itemKey)] = null;
            }
        }

        return $map;
    }

    /**
     * Build empty sign-off values.
     *
     * @return array<string, string>
     */
    public function emptySignOffMap(EquipmentInspectionArea $area): array
    {
        $map = [];

        foreach ($this->definition->signOffFields($area) as $key => $field) {
            $map[$key] = '';
        }

        return $map;
    }

    /**
     * Compose a stable checklist key.
     */
    public function checklistKey(string $section, string $itemKey): string
    {
        return "{$section}|{$itemKey}";
    }

    /**
     * Determine whether a register row has no meaningful content.
     *
     * @param  array<string, mixed>  $row
     */
    public function registerRowIsEmpty(array $row): bool
    {
        return blank($row['item_date'] ?? null)
            && blank($row['department'] ?? null)
            && blank($row['equipment'] ?? null)
            && blank($row['problem'] ?? null)
            && blank($row['action_taken'] ?? null)
            && blank($row['technician'] ?? null)
            && blank($row['completed_date'] ?? null)
            && blank($row['signed'] ?? null);
    }

    /**
     * Persist a completed inspection and notify admins when faults exist.
     *
     * @param  array{checked_by_name: string, supervisor_name: string}  $header
     * @param  array<string, array{present: bool|null, functional: bool|null, clean: bool|null, maint_req: bool|null, remarks: string}>  $equipment
     * @param  array<string, bool|null>  $checklist
     * @param  array<string, string>  $signOff
     * @param  list<array<string, mixed>>  $registerRows
     */
    public function submit(
        User $nurse,
        EquipmentInspectionArea $area,
        Carbon $date,
        EquipmentInspectionShift $shift,
        array $header,
        array $equipment,
        array $checklist,
        array $signOff,
        array $registerRows = [],
    ): EquipmentInspectionEntry {
        if ($this->findEntry($area, $date, $shift) !== null) {
            throw ValidationException::withMessages([
                'shift' => __('This shift checklist has already been submitted.'),
            ]);
        }

        $entry = DB::transaction(function () use ($nurse, $area, $date, $shift, $header, $equipment, $checklist, $signOff, $registerRows): EquipmentInspectionEntry {
            $entry = EquipmentInspectionEntry::create([
                'user_id' => $nurse->id,
                'health_aide_id' => $header['health_aide_id'] ?? null,
                'area' => $area,
                'checklist_date' => $date->toDateString(),
                'shift' => $shift,
                'checked_by_name' => $header['checked_by_name'],
                'supervisor_name' => $header['supervisor_name'] !== '' ? $header['supervisor_name'] : null,
                'sign_off' => $signOff,
                'submitted_at' => now(),
            ]);

            foreach ($this->definition->equipmentItems($area) as $itemKey => $item) {
                $row = $equipment[$itemKey];

                EquipmentInspectionAnswer::create([
                    'entry_id' => $entry->id,
                    'section' => 'A',
                    'item_key' => $itemKey,
                    'present' => (bool) $row['present'],
                    'functional' => (bool) $row['functional'],
                    'clean' => (bool) $row['clean'],
                    'maint_req' => (bool) $row['maint_req'],
                    'remarks' => $row['remarks'] !== '' ? $row['remarks'] : null,
                ]);
            }

            foreach ($this->definition->checklistSections($area) as $section) {
                foreach ($this->definition->checklistItems($area, $section) as $itemKey => $label) {
                    $key = $this->checklistKey($section, $itemKey);

                    EquipmentInspectionAnswer::create([
                        'entry_id' => $entry->id,
                        'section' => $section,
                        'item_key' => $itemKey,
                        'checked' => (bool) $checklist[$key],
                    ]);
                }
            }

            if ($area->isRegister()) {
                foreach ($registerRows as $index => $row) {
                    if ($this->registerRowIsEmpty($row)) {
                        continue;
                    }

                    EquipmentInspectionRegisterRow::create([
                        'entry_id' => $entry->id,
                        'item_date' => $row['item_date'] !== '' ? $row['item_date'] : null,
                        'department' => $row['department'] !== '' ? $row['department'] : null,
                        'equipment' => $row['equipment'] !== '' ? $row['equipment'] : null,
                        'problem' => $row['problem'] !== '' ? $row['problem'] : null,
                        'action_taken' => $row['action_taken'] !== '' ? $row['action_taken'] : null,
                        'technician' => $row['technician'] !== '' ? $row['technician'] : null,
                        'completed_date' => $row['completed_date'] !== '' ? $row['completed_date'] : null,
                        'signed' => $row['signed'] !== '' ? $row['signed'] : null,
                        'sort_order' => $index,
                    ]);
                }
            }

            return $entry;
        });

        $entry->load(['answers', 'registerRows', 'user']);

        if ($entry->hasFaults()) {
            $this->notifications->notifyEquipmentInspectionFaults($nurse, $entry);
        }

        return $entry;
    }
}
