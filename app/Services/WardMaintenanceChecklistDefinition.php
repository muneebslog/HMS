<?php

namespace App\Services;

class WardMaintenanceChecklistDefinition
{
    /**
     * Bed location keys used across bed-level checks.
     *
     * @return list<string>
     */
    public function beds(): array
    {
        return ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9'];
    }

    /**
     * Area location keys used for room-level checks.
     *
     * @return array<string, string>
     */
    public function areas(): array
    {
        return [
            'gyne_ward' => __('Gyne Ward'),
            'private_1' => __('Private 1'),
            'private_2' => __('Private 2'),
            'shared_private' => __('Shared Private'),
        ];
    }

    /**
     * Private-room location keys for AC / private electrical checks.
     *
     * @return array<string, string>
     */
    public function privateAreas(): array
    {
        return [
            'private_1' => __('Priv 1'),
            'private_2' => __('Priv 2'),
            'shared_private' => __('Shared'),
        ];
    }

    /**
     * Bathroom location keys.
     *
     * @return array<string, string>
     */
    public function bathrooms(): array
    {
        return [
            'gyne_ward' => __('Gyne Ward Bathroom'),
            'private_1' => __('Private Room 1'),
            'private_2' => __('Private Room 2'),
            'shared_private' => __('Shared Private Room'),
        ];
    }

    /**
     * Section A: bed & bedside equipment items.
     *
     * @return array<string, string>
     */
    public function sectionAItems(): array
    {
        return [
            'bed_condition' => __('Bed condition'),
            'bed_wheels_brakes' => __('Bed wheels / brakes'),
            'side_rails' => __('Side rails'),
            'bed_adjustment' => __('Bed adjustment mechanism'),
            'mattress' => __('Mattress condition & cleanliness'),
            'bedside_table' => __('Bedside table / locker'),
            'patient_chair' => __('Patient / Attendant chair'),
            'iv_stand' => __('IV stand'),
            'call_bell' => __('Call bell / communication system'),
        ];
    }

    /**
     * Section B: room / area infrastructure items.
     *
     * @return array<string, string>
     */
    public function sectionBItems(): array
    {
        return [
            'floor' => __('Floor condition & cleanliness'),
            'walls' => __('Walls condition & paint'),
            'ceiling' => __('Ceiling condition / No active leakage'),
            'doors' => __('Doors & handles functioning'),
            'windows' => __('Windows & latches intact'),
            'curtains' => __('Curtains / Blinds functional & clean'),
            'no_water_leakage' => __('No water leakage observed'),
            'no_dampness' => __('No dampness / mold signs'),
            'no_pest' => __('No pest activity detected'),
            'overall' => __('Overall general area condition'),
        ];
    }

    /**
     * Section C: Gyne Ward (non-AC) electrical items.
     *
     * @return array<string, string>
     */
    public function sectionCGyneItems(): array
    {
        return [
            'main_lights' => __('Main room lights functioning'),
            'bedside_lights' => __('Bedside / required lights functioning'),
            'ceiling_fans' => __('Ceiling fans functioning'),
            'fan_regulators' => __('Fan regulators / switches functioning'),
            'sockets' => __('Electrical sockets functioning'),
            'switches' => __('Switches intact & secure'),
            'no_exposed_wires' => __('No exposed or damaged wires'),
            'no_sparking' => __('No sparking or burning smell'),
            'emergency_lighting' => __('Emergency / backup lighting functional'),
        ];
    }

    /**
     * Section C: Private rooms (AC) electrical items.
     *
     * @return array<string, string>
     */
    public function sectionCPrivateItems(): array
    {
        return [
            'room_lights' => __('Room lights'),
            'bedside_lights' => __('Bedside lights'),
            'ac_cooling' => __('AC cooling functioning'),
            'ac_control' => __('AC remote / wall control'),
            'sockets' => __('Electrical sockets'),
            'switches' => __('Switches intact'),
            'no_exposed_wires' => __('No exposed wires'),
            'no_sparking' => __('No smell / sparking'),
        ];
    }

    /**
     * Section D: Gyne ward bathroom items.
     *
     * @return array<string, string>
     */
    public function sectionDGyneItems(): array
    {
        return [
            'water_supply' => __('Water supply available'),
            'wash_basin' => __('Wash basin functioning'),
            'taps' => __('Taps functioning'),
            'toilet' => __('Toilet functioning'),
            'flush' => __('Flush system operational'),
            'drainage' => __('Drainage clear'),
            'no_leakage' => __('No water leakage'),
            'no_blocked_drain' => __('No blocked drain'),
            'no_foul_smell' => __('No foul drainage smell'),
            'light' => __('Bathroom light working'),
        ];
    }

    /**
     * Section D: Private / shared bathroom items.
     *
     * @return array<string, string>
     */
    public function sectionDPrivateItems(): array
    {
        return [
            'water_supply' => __('Water supply available'),
            'wash_basin' => __('Wash basin functioning'),
            'taps' => __('Taps functioning'),
            'shower' => __('Shower functioning'),
            'toilet' => __('Toilet functioning'),
            'flush' => __('Flush operational'),
            'drainage' => __('Drainage clear'),
            'no_leakage' => __('No water leakage'),
            'light' => __('Light functioning'),
            'hot_water' => __('Hot water operational'),
        ];
    }

    /**
     * Section E: medical / patient-care equipment.
     *
     * @return array<string, string>
     */
    public function sectionEItems(): array
    {
        return [
            'bp_apparatus' => __('BP Apparatus'),
            'stethoscope' => __('Stethoscope'),
            'pulse_oximeter' => __('Pulse Oximeter'),
            'thermometer' => __('Thermometer'),
            'glucometer' => __('Glucometer'),
            'weighing_scale' => __('Weighing Scale'),
            'wheelchair' => __('Wheelchair'),
            'stretcher' => __('Stretcher'),
            'oxygen_cylinder' => __('Oxygen Cylinder / Backup Supply'),
            'oxygen_regulator' => __('Oxygen Regulator / Flowmeter'),
            'oxygen_masks' => __('Oxygen Masks & Tubing'),
            'suction' => __('Suction Equipment (if provided)'),
            'nebulizer' => __('Nebulizer'),
            'fetal_doppler' => __('Fetal Doppler (if required)'),
            'ctg' => __('CTG / Fetal Monitor (if provided)'),
            'examination_couch' => __('Examination Couch'),
        ];
    }

    /**
     * Section F: common area check items.
     *
     * @return array<string, string>
     */
    public function sectionFItems(): array
    {
        return [
            'nurses_station' => __('Nurses\' station functional'),
            'nurse_call' => __('Nurse call system functional'),
            'medication_prep' => __('Medication prep area clean'),
            'utility_sink' => __('Utility / sink area functional'),
            'clean_utility' => __('Clean utility area functional'),
            'dirty_utility' => __('Dirty utility / waste area clear'),
            'linen_storage' => __('Linen storage area organized'),
            'wheelchair_available' => __('Wheelchair readily available'),
            'stretcher_available' => __('Stretcher readily available'),
            'emergency_trolley' => __('Emergency trolley accessible'),
            'corridor_lighting' => __('Corridor lighting functional'),
            'corridor_clear' => __('Corridor free of obstruction'),
            'fire_exit' => __('Fire exit accessible & clear'),
            'fire_extinguisher' => __('Fire extinguisher accessible'),
        ];
    }

    /**
     * Section G: safety check items.
     *
     * @return array<string, string>
     */
    public function sectionGItems(): array
    {
        return [
            'no_slippery' => __('No slippery or tripping hazards'),
            'no_broken_furniture' => __('No broken furniture in patient zones'),
            'no_sharp_edges' => __('No sharp exposed edges'),
            'bed_brakes' => __('All bed brakes functioning properly'),
            'emergency_pathways' => __('Emergency pathways completely clear'),
            'fire_extinguishers' => __('Fire extinguishers in position & valid'),
            'emergency_exit' => __('Emergency exit accessible'),
            'no_electrical_hazards' => __('Electrical hazards completely absent'),
            'oxygen_secured' => __('Oxygen equipment safely secured'),
            'call_system' => __('Patient call system fully functional'),
        ];
    }

    /**
     * Build every required status cell for validation / persistence.
     *
     * @return list<array{section: string, item_key: string, location_key: string, label: string}>
     */
    public function statusCells(): array
    {
        $cells = [];

        foreach ($this->sectionAItems() as $itemKey => $label) {
            foreach ($this->beds() as $bed) {
                $cells[] = [
                    'section' => 'A',
                    'item_key' => $itemKey,
                    'location_key' => $bed,
                    'label' => "{$label} ({$bed})",
                ];
            }
        }

        foreach ($this->sectionBItems() as $itemKey => $label) {
            foreach ($this->areas() as $locationKey => $locationLabel) {
                $cells[] = [
                    'section' => 'B',
                    'item_key' => $itemKey,
                    'location_key' => $locationKey,
                    'label' => "{$label} ({$locationLabel})",
                ];
            }
        }

        foreach ($this->sectionCGyneItems() as $itemKey => $label) {
            $cells[] = [
                'section' => 'C_gyne',
                'item_key' => $itemKey,
                'location_key' => 'gyne_ward',
                'label' => $label.' ('.__('Gyne Ward').')',
            ];
        }

        foreach ($this->sectionCPrivateItems() as $itemKey => $label) {
            foreach ($this->privateAreas() as $locationKey => $locationLabel) {
                $cells[] = [
                    'section' => 'C_private',
                    'item_key' => $itemKey,
                    'location_key' => $locationKey,
                    'label' => "{$label} ({$locationLabel})",
                ];
            }
        }

        foreach ($this->sectionDGyneItems() as $itemKey => $label) {
            $cells[] = [
                'section' => 'D',
                'item_key' => $itemKey,
                'location_key' => 'gyne_ward',
                'label' => $label.' ('.__('Gyne Ward Bathroom').')',
            ];
        }

        foreach (['private_1', 'private_2', 'shared_private'] as $locationKey) {
            $locationLabel = $this->bathrooms()[$locationKey];

            foreach ($this->sectionDPrivateItems() as $itemKey => $label) {
                $cells[] = [
                    'section' => 'D',
                    'item_key' => $itemKey,
                    'location_key' => $locationKey,
                    'label' => "{$label} ({$locationLabel})",
                ];
            }
        }

        foreach ($this->sectionFItems() as $itemKey => $label) {
            $cells[] = [
                'section' => 'F',
                'item_key' => $itemKey,
                'location_key' => '',
                'label' => $label,
            ];
        }

        foreach ($this->sectionGItems() as $itemKey => $label) {
            $cells[] = [
                'section' => 'G',
                'item_key' => $itemKey,
                'location_key' => '',
                'label' => $label,
            ];
        }

        return $cells;
    }
}
