<?php

namespace App\Services;

class EmergencyDepartmentLogDefinition
{
    /**
     * Section A: department summary & handover metrics.
     *
     * @return array<string, string>
     */
    public function handoverMetrics(): array
    {
        return [
            'total_patients' => __('Total Patients'),
            'observation_patients' => __('Observation Patients'),
            'drips_running' => __('Drips Running'),
            'injection_patients_waiting' => __('Injection Patients Waiting'),
            'admissions_waiting' => __('Admissions Waiting'),
            'referrals_waiting' => __('Referrals Waiting'),
            'discharges_pending' => __('Discharges Pending'),
            'critical_patients' => __('Critical Patients'),
        ];
    }

    /**
     * Section B: emergency equipment items.
     *
     * @return array<string, array{label: string, expected: string}>
     */
    public function equipmentItems(): array
    {
        return [
            'monitor' => ['label' => __('Monitor'), 'expected' => __('Working')],
            'suction' => ['label' => __('Suction'), 'expected' => __('Working')],
            'oxygen_outlets' => ['label' => __('Oxygen Outlets & Flowmeters'), 'expected' => __('Working')],
            'nebulizer' => ['label' => __('Nebulizer'), 'expected' => __('Working')],
            'pulse_oximeter' => ['label' => __('Pulse Oximeter'), 'expected' => __('Available')],
            'bp_apparatus' => ['label' => __('BP Apparatus'), 'expected' => __('Available')],
            'ecg_machine' => ['label' => __('ECG Machine'), 'expected' => __('Working')],
            'defibrillator' => ['label' => __('Defibrillator'), 'expected' => __('Ready')],
            'ambu_bag' => ['label' => __('Ambu Bag'), 'expected' => __('Available')],
        ];
    }

    /**
     * Section C: crash cart / ER trolley stock by drawer.
     *
     * @return array<string, array{label: string, items: array<string, array{label: string, par: int|null}>}>
     */
    public function crashCartDrawers(): array
    {
        return [
            'C1' => [
                'label' => __('Drawer 1: Emergency Drugs'),
                'items' => [
                    'adrenaline' => ['label' => __('Adrenaline'), 'par' => 5],
                    'atropine' => ['label' => __('Atropine'), 'par' => 5],
                    'dopamine' => ['label' => __('Dopamine'), 'par' => 2],
                    'mgso4' => ['label' => __('MgSO4'), 'par' => 2],
                    'calcium_gluconate' => ['label' => __('Calcium Gluconate'), 'par' => 5],
                    'hydralazine' => ['label' => __('Hydralazine'), 'par' => 1],
                    'labetalol' => ['label' => __('Labetalol'), 'par' => 2],
                    'dexamethasone' => ['label' => __('Dexamethasone'), 'par' => 5],
                    'lasix' => ['label' => __('Lasix'), 'par' => 5],
                    'ondansetron' => ['label' => __('Ondansetron'), 'par' => 5],
                    'tranexamic_acid' => ['label' => __('Tranexamic Acid'), 'par' => 5],
                    'hydrocortisone' => ['label' => __('Hydrocortisone'), 'par' => 2],
                ],
            ],
            'C2' => [
                'label' => __('Drawer 2: IV Access & Diagnostics'),
                'items' => [
                    'iv_cannula_18g' => ['label' => __('IV Cannula 18G'), 'par' => null],
                    'iv_cannula_20g' => ['label' => __('IV Cannula 20G'), 'par' => null],
                    'iv_cannula_22g' => ['label' => __('IV Cannula 22G'), 'par' => null],
                    'iv_cannula_24g' => ['label' => __('IV Cannula 24G'), 'par' => null],
                    'iv_sets' => ['label' => __('IV Sets'), 'par' => null],
                    'burette_set' => ['label' => __('Burette Set'), 'par' => null],
                    'steri_strips' => ['label' => __('Steri-Strips'), 'par' => null],
                    'syringes' => ['label' => __('Syringes (Assorted)'), 'par' => null],
                    'ent_box' => ['label' => __('ENT Box'), 'par' => null],
                    'pulse_oximeter_probe' => ['label' => __('Pulse Oximeter Probe'), 'par' => null],
                ],
            ],
            'C3' => [
                'label' => __('Drawer 3: Airway & Tubes'),
                'items' => [
                    'airway_guedel' => ['label' => __('Airway (Guedel)'), 'par' => null],
                    'endotracheal_tube' => ['label' => __('Endotracheal Tube'), 'par' => null],
                    'oxygen_cannula' => ['label' => __('Oxygen Cannula'), 'par' => null],
                    'foley_catheter' => ['label' => __('Foley Catheter'), 'par' => null],
                    'nelaton_catheter' => ['label' => __('Nelaton Catheter'), 'par' => null],
                    'feeding_tube' => ['label' => __('Feeding Tube'), 'par' => null],
                ],
            ],
            'C4' => [
                'label' => __('Drawer 4: Consumables'),
                'items' => [
                    'surgical_gloves' => ['label' => __('Surgical Gloves'), 'par' => null],
                    'examination_gloves' => ['label' => __('Examination Gloves'), 'par' => null],
                    'ear_syringe' => ['label' => __('Ear Syringe'), 'par' => null],
                    'crash_ambu_bag' => ['label' => __('Ambu Bag'), 'par' => null],
                    'oxygen_mask' => ['label' => __('Oxygen Mask'), 'par' => null],
                    'suction_set' => ['label' => __('Suction Set'), 'par' => null],
                    'micropore_tape' => ['label' => __('Micropore Tape'), 'par' => null],
                    'caps' => ['label' => __('Caps'), 'par' => null],
                ],
            ],
            'C5' => [
                'label' => __('Drawer 5: IV Fluids'),
                'items' => [
                    'ns_100' => ['label' => __('NS 100 mL'), 'par' => null],
                    'ns_500' => ['label' => __('NS 500 mL'), 'par' => null],
                    'ns_1000' => ['label' => __('NS 1000 mL'), 'par' => null],
                    'rl_500' => ['label' => __('RL 500 mL'), 'par' => null],
                    'rl_1000' => ['label' => __('RL 1000 mL'), 'par' => null],
                    'dextrose_flagyl' => ['label' => __('Dextrose / Flagyl'), 'par' => null],
                ],
            ],
        ];
    }

    /**
     * Section D: cleaning & facility maintenance groups.
     *
     * @return array<string, array{label: string, items: array<string, string>}>
     */
    public function cleaningGroups(): array
    {
        return [
            'D1' => [
                'label' => __('1. Start-of-Shift / Daily Cleaning'),
                'items' => [
                    'floors_clean' => __('Floors clean, dry, free from spills or hazards.'),
                    'beds_stretchers' => __('Patient beds & stretchers cleaned and disinfected.'),
                    'bed_rails' => __('Bed rails, mattresses, and side tables disinfected.'),
                    'examination_couches' => __('Examination couches cleaned and prepared.'),
                    'high_touch' => __('High-touch surfaces, door handles & push plates disinfected.'),
                    'workstations' => __('Workstations, keyboards, and telephones cleaned.'),
                    'waiting_furniture' => __('Chairs and waiting-area furniture clean.'),
                    'hand_hygiene_stations' => __('Hand hygiene stations clean and fully stocked.'),
                    'waste_sharps' => __('Waste bins emptied; sharps containers checked & replaced.'),
                    'toilets' => __('Toilets and washrooms cleaned and supplied.'),
                ],
            ],
            'D2' => [
                'label' => __('2. Patient Area Cleaning (Between Patients)'),
                'items' => [
                    'bed_after_patient' => __('Bed or stretcher cleaned after every patient.'),
                    'fresh_linen' => __('Fresh linen provided; used linen removed safely.'),
                    'spills' => __('Spills (blood/body fluids) cleaned per infection policy.'),
                    'disposable_items' => __('Disposable items from previous patient removed.'),
                    'medical_equipment_disinfected' => __('Frequently touched medical equipment disinfected.'),
                    'privacy_curtains' => __('Privacy curtains checked and changed when soiled.'),
                ],
            ],
            'D3' => [
                'label' => __('3. Equipment Cleaning & Maintenance'),
                'items' => [
                    'ecg_defib_suction_cleaned' => __('ECG machine, Defibrillator & Suction cleaned & checked.'),
                    'oxygen_nebulizers_cleaned' => __('Oxygen outlets, flowmeters & Nebulizers cleaned per protocol.'),
                    'monitors_bp_spo2_disinfected' => __('Patient monitors, BP cuffs & Pulse oximeters disinfected.'),
                    'thermometers_glucometer' => __('Thermometers disinfected; glucometer quality checks done.'),
                    'wheelchairs_stretchers' => __('Wheelchairs & stretchers clean and in good condition.'),
                    'crash_cart_exterior' => __('Emergency trolley/crash cart exterior cleaned.'),
                ],
            ],
            'D4' => [
                'label' => __('4. Infection Prevention & Safety'),
                'items' => [
                    'ppe_stocked' => __('PPE available and adequately stocked.'),
                    'sanitizer_filled' => __('Hand sanitizer dispensers filled and functioning.'),
                    'soap_towels' => __('Soap and paper towels available at sinks.'),
                    'isolation_cleaned' => __('Isolation areas cleaned according to protocol.'),
                    'cleaning_supplies' => __('Cleaning supplies properly labeled & within expiry.'),
                    'decontam_waste' => __('Reusable equipment decontaminated; waste segregated.'),
                ],
            ],
            'D5' => [
                'label' => __('5. Facility & Maintenance Checks'),
                'items' => [
                    'lighting_sockets' => __('Lighting and electrical sockets intact and safe.'),
                    'ac_ventilation' => __('Air conditioning / ventilation functioning properly.'),
                    'water_drainage' => __('Water supply, sinks, and drainage clear & functioning.'),
                    'walls_ceilings_doors' => __('Walls, ceilings, and doors free from visible damage.'),
                    'emergency_exits' => __('Emergency exits clear, accessible, and unobstructed.'),
                    'fire_safety' => __('Fire safety equipment present and accessible.'),
                    'call_bells' => __('Call bells or patient alarm systems functioning.'),
                ],
            ],
            'D6' => [
                'label' => __('6. End-of-Shift & Scheduled Tasks'),
                'items' => [
                    'patient_areas_end' => __('All patient-care areas cleaned and disinfected.'),
                    'equipment_returned' => __('Used equipment cleaned & returned to designated area.'),
                    'waste_linen_end' => __('Waste removed; sharps checked; linen replaced.'),
                    'weekly_deep_clean' => __('Weekly deep cleaning of ER treatment areas completed.'),
                    'storage_checked' => __('Storage rooms & temperature-sensitive storage checked.'),
                    'maintenance_records' => __('Equipment maintenance schedule & records updated.'),
                ],
            ],
        ];
    }

    /**
     * Flatten crash-cart items across drawers.
     *
     * @return list<array{section: string, item_key: string, label: string, par: int|null}>
     */
    public function crashCartItems(): array
    {
        $items = [];

        foreach ($this->crashCartDrawers() as $section => $drawer) {
            foreach ($drawer['items'] as $itemKey => $item) {
                $items[] = [
                    'section' => $section,
                    'item_key' => $itemKey,
                    'label' => $this->crashCartItemLabel($item),
                    'par' => $item['par'],
                ];
            }
        }

        return $items;
    }

    /**
     * Flatten cleaning items across groups.
     *
     * @return list<array{section: string, item_key: string, label: string}>
     */
    public function cleaningItems(): array
    {
        $items = [];

        foreach ($this->cleaningGroups() as $section => $group) {
            foreach ($group['items'] as $itemKey => $label) {
                $items[] = [
                    'section' => $section,
                    'item_key' => $itemKey,
                    'label' => $label,
                ];
            }
        }

        return $items;
    }

    /**
     * Display label for a crash-cart item, including par level when set.
     *
     * @param  array{label: string, par: int|null}  $item
     */
    public function crashCartItemLabel(array $item): string
    {
        if ($item['par'] === null) {
            return $item['label'];
        }

        return $item['label'].' ('.$item['par'].')';
    }

    /**
     * All item labels keyed by item_key for notifications and views.
     *
     * @return array<string, string>
     */
    public function allItemLabels(): array
    {
        $labels = $this->handoverMetrics();

        foreach ($this->equipmentItems() as $itemKey => $item) {
            $labels[$itemKey] = $item['label'];
        }

        foreach ($this->crashCartItems() as $item) {
            $labels[$item['item_key']] = $item['label'];
        }

        foreach ($this->cleaningItems() as $item) {
            $labels[$item['item_key']] = $item['label'];
        }

        return $labels;
    }
}
