<?php

namespace App\Services;

use App\Enums\EquipmentInspectionArea;

class EquipmentInspectionChecklistDefinition
{
    /**
     * @return list<EquipmentInspectionArea>
     */
    public function areas(): array
    {
        return EquipmentInspectionArea::cases();
    }

    /**
     * Section navigation for an area form wizard.
     *
     * @return list<array{key: string, label: string}>
     */
    public function sections(EquipmentInspectionArea $area): array
    {
        return match ($area) {
            EquipmentInspectionArea::ConsultationRoom => [
                ['key' => 'header', 'label' => __('Header')],
                ['key' => 'A', 'label' => __('A. Equipment & Furniture')],
                ['key' => 'B', 'label' => __('B. Cleaning Verification')],
                ['key' => 'C', 'label' => __('C. Maintenance')],
                ['key' => 'signoff', 'label' => __('Sign-off')],
            ],
            EquipmentInspectionArea::LabourRoom => [
                ['key' => 'header', 'label' => __('Header')],
                ['key' => 'A', 'label' => __('A. Critical Equipment')],
                ['key' => 'B', 'label' => __('B. Turnover Cleaning')],
                ['key' => 'C', 'label' => __('C. Emergency Readiness')],
                ['key' => 'signoff', 'label' => __('Sign-off')],
            ],
            EquipmentInspectionArea::OperationTheatre => [
                ['key' => 'header', 'label' => __('Header')],
                ['key' => 'A', 'label' => __('A. Major OT Equipment')],
                ['key' => 'B', 'label' => __('B. Pre-Opening Check')],
                ['key' => 'C', 'label' => __('C. Between Cases Turnover')],
                ['key' => 'D', 'label' => __('D. End-of-Day Terminal Clean')],
                ['key' => 'signoff', 'label' => __('Sign-off')],
            ],
            EquipmentInspectionArea::Decontamination => [
                ['key' => 'header', 'label' => __('Header')],
                ['key' => 'A', 'label' => __('A. Equipment & Facility')],
                ['key' => 'B', 'label' => __('B. Daily Hygiene')],
                ['key' => 'C', 'label' => __('C. Weekly Maintenance')],
                ['key' => 'signoff', 'label' => __('Sign-off')],
            ],
            EquipmentInspectionArea::MaintenanceRegister => [
                ['key' => 'header', 'label' => __('Header')],
                ['key' => 'register', 'label' => __('Maintenance Register')],
                ['key' => 'signoff', 'label' => __('Sign-off')],
            ],
        };
    }

    /**
     * Equipment items for section A (multi-column checks).
     *
     * @return array<string, array{label: string, hint: string|null}>
     */
    public function equipmentItems(EquipmentInspectionArea $area): array
    {
        return match ($area) {
            EquipmentInspectionArea::ConsultationRoom => [
                'examination_couch' => ['label' => __('Examination couch'), 'hint' => null],
                'doctors_chair' => ['label' => __('Doctor\'s chair'), 'hint' => null],
                'patient_chairs' => ['label' => __('Patient chair(s)'), 'hint' => null],
                'examination_light' => ['label' => __('Examination light'), 'hint' => null],
                'bp_apparatus' => ['label' => __('BP apparatus (Aneroid/Digital)'), 'hint' => __('Check cuff integrity')],
                'stethoscope' => ['label' => __('Stethoscope'), 'hint' => null],
                'pulse_oximeter' => ['label' => __('Pulse oximeter'), 'hint' => __('Check battery / probe')],
                'thermometer' => ['label' => __('Thermometer (Digital/IR)'), 'hint' => null],
                'weighing_scale' => ['label' => __('Weighing scale'), 'hint' => __('Verify zero calibration')],
                'examination_instruments' => ['label' => __('Examination instruments'), 'hint' => null],
                'instrument_tray' => ['label' => __('Instrument tray'), 'hint' => null],
                'computer_laptop' => ['label' => __('Computer / Laptop'), 'hint' => __('Network connection OK')],
                'printer' => ['label' => __('Printer'), 'hint' => __('Paper/toner sufficient')],
                'telephone_intercom' => ['label' => __('Telephone / Intercom'), 'hint' => null],
                'waste_bins' => ['label' => __('Waste bins (General / Clinical)'), 'hint' => __('Liners installed')],
                'hand_washing_facility' => ['label' => __('Hand-washing facility / Sink'), 'hint' => __('Soap/towels present')],
                'hand_sanitizer' => ['label' => __('Hand sanitizer dispenser'), 'hint' => __('Filled and functional')],
            ],
            EquipmentInspectionArea::LabourRoom => [
                'delivery_bed' => ['label' => __('Delivery bed (Multi-position)'), 'hint' => __('Test height/tilt locks')],
                'instrument_trolley' => ['label' => __('Instrument trolley'), 'hint' => __('Smooth mobility')],
                'foot_step' => ['label' => __('Foot step / Stool'), 'hint' => __('Anti-slip mat intact')],
                'ultrasound' => ['label' => __('Ultrasound machine'), 'hint' => __('Gel & probes clean')],
                'drip_stand' => ['label' => __('Drip stand / IV pole'), 'hint' => __('Height adjust functional')],
                'bp_stethoscope' => ['label' => __('BP apparatus & Stethoscope'), 'hint' => null],
                'pulse_oximeter' => ['label' => __('Pulse oximeter (Maternal/Neonatal)'), 'hint' => __('Sensors operational')],
                'thermometer' => ['label' => __('Thermometer'), 'hint' => null],
                'suction_apparatus' => ['label' => __('Suction apparatus (Central/Portable)'), 'hint' => __('Test pressure & tubing')],
                'oxygen_supply' => ['label' => __('Oxygen supply / Cylinder'), 'hint' => __('Pressure > 1000 PSI')],
                'oxygen_flowmeter' => ['label' => __('Oxygen flowmeter / Regulator'), 'hint' => __('Humidifier jar filled')],
                'emergency_crash_cart' => ['label' => __('Emergency Crash Cart'), 'hint' => __('Seal intact / verified')],
                'ambu_bags' => ['label' => __('Ambu bags (Adult & Neonatal)'), 'hint' => __('Check valves & masks')],
                'neonatal_resuscitation' => ['label' => __('Neonatal resuscitation unit'), 'hint' => __('Laryngoscope/ET tubes ready')],
                'baby_weighing_scale' => ['label' => __('Baby weighing scale'), 'hint' => __('Clean paper liner present')],
                'radiant_baby_warmer' => ['label' => __('Radiant Baby Warmer'), 'hint' => __('Pre-heat function verified')],
                'examination_light' => ['label' => __('Examination light'), 'hint' => __('Focus & intensity OK')],
                'instrument_trays_sterile' => ['label' => __('Instrument trays (Sterile)'), 'hint' => __('Sterilization indicator OK')],
                'biohazard_waste_bins' => ['label' => __('Biohazard & Waste Bins'), 'hint' => __('Color-coded liners')],
                'sharps_container' => ['label' => __('Sharps container'), 'hint' => __('Rigid, puncture-proof')],
            ],
            EquipmentInspectionArea::OperationTheatre => [
                'ot_table' => ['label' => __('OT Table (Hydraulic/Electric)'), 'hint' => __('Test controls, brake, tilt')],
                'anaesthesia_machine' => ['label' => __('Anaesthesia Machine'), 'hint' => __('Authorized tech check done')],
                'surgical_ventilator' => ['label' => __('Surgical Ventilator'), 'hint' => __('Self-test & circuit test OK')],
                'cardiac_monitor' => ['label' => __('Multiparameter Cardiac Monitor'), 'hint' => __('ECG, NIBP, SpO2, Temp probes')],
                'suction_twin_jar' => ['label' => __('Suction Apparatus (Twin Jar)'), 'hint' => __('Vacuum pressure verified')],
                'surgical_overhead_light' => ['label' => __('Surgical Overhead Light'), 'hint' => __('Intensity & positioning arms OK')],
                'electrosurgical_unit' => ['label' => __('Electrosurgical / Cautery Unit'), 'hint' => __('Patient plate & footswitch OK')],
                'oxygen_system' => ['label' => __('Central / Cylinder Oxygen System'), 'hint' => __('Backup manifold verified')],
                'laryngoscope_sets' => ['label' => __('Laryngoscope Sets (Adult/Ped)'), 'hint' => __('Blades and bright light test')],
                'intubation_airway_cart' => ['label' => __('Intubation Equipment / Airway Cart'), 'hint' => __('ET tubes, stylets, LMA ready')],
                'ambu_bag' => ['label' => __('Ambu Bag (Adult/Child)'), 'hint' => __('Pop-off valve functional')],
                'instrument_trolleys' => ['label' => __('Instrument Trolleys'), 'hint' => __('Clean, castors working')],
                'mayo_stand' => ['label' => __('Mayo Stand / Trolley'), 'hint' => __('Height adjust functional')],
                'iv_stands_pumps' => ['label' => __('IV Stands / Infusion Pumps'), 'hint' => __('Battery backup verified')],
                'ot_step' => ['label' => __('OT Step / Footstool'), 'hint' => __('Stable, non-slip feet')],
                'patient_transfer_trolley' => ['label' => __('Patient Transfer Trolley / Stretcher'), 'hint' => __('Side rails & straps intact')],
                'kidney_trays' => ['label' => __('Kidney Trays & Utility Trolleys'), 'hint' => __('Stainless steel clean')],
                'sterile_instrument_sets' => ['label' => __('Sterile Instrument Sets'), 'hint' => __('Packs intact / indicators OK')],
                'sharps_disposal' => ['label' => __('Sharps Disposal Container'), 'hint' => __('Mounted, fill level checked')],
                'biomedical_waste_bins' => ['label' => __('Biomedical Waste Bins (Color-coded)'), 'hint' => __('Foot pedal functional')],
            ],
            EquipmentInspectionArea::Decontamination => [
                'washing_sink' => ['label' => __('Instrument washing sink (Deep bay)'), 'hint' => __('Check drain stopper & basin')],
                'running_water' => ['label' => __('Running water supply (Hot/Cold)'), 'hint' => __('Adequate pressure verified')],
                'cleaning_brushes' => ['label' => __('Instrument cleaning brushes (Nylon/Soft)'), 'hint' => __('Inspect brush wear/integrity')],
                'decontam_trays' => ['label' => __('Decontamination instrument trays'), 'hint' => __('Perforated mesh type')],
                'kidney_trays_basins' => ['label' => __('Kidney trays / Soak basins'), 'hint' => __('Enzymatic solution ready')],
                'dirty_utility_trolley' => ['label' => __('Dirty utility transport trolley'), 'hint' => __('Enclosed/covered transport')],
                'drying_area' => ['label' => __('Drying area / Lint-free drying rack'), 'hint' => __('Air dryer / compressed air functional')],
                'ppe_storage' => ['label' => __('PPE Storage Station'), 'hint' => __('Heavy-duty gloves, aprons, visors')],
                'biomedical_waste_bins' => ['label' => __('Biomedical waste bins'), 'hint' => __('Lined per biohazard rules')],
                'sharps_disposal' => ['label' => __('Sharps disposal container'), 'hint' => __('Rigid container near sink')],
                'enzymatic_chemicals' => ['label' => __('Enzymatic cleaning / Disinfectant chemicals'), 'hint' => __('Dilution ratios posted & checked')],
                'transport_containers' => ['label' => __('Leak-proof instrument transport containers'), 'hint' => __('Latching lids functional')],
            ],
            EquipmentInspectionArea::MaintenanceRegister => [],
        };
    }

    /**
     * Simple checklist items keyed by section (B/C/D).
     *
     * @return array<string, string>
     */
    public function checklistItems(EquipmentInspectionArea $area, string $section): array
    {
        return match ($area) {
            EquipmentInspectionArea::ConsultationRoom => match ($section) {
                'B' => [
                    'couch_disinfected' => __('Examination couch disinfected'),
                    'surfaces_cleaned' => __('Examination table / surfaces cleaned'),
                    'bp_gear_wiped' => __('BP apparatus & medical gear wiped'),
                    'high_touch_wiped' => __('High-touch points (door handles) wiped'),
                    'chairs_desk_wiped' => __('Chairs and desk wiped down'),
                    'keyboard_disinfected' => __('Computer keyboard and mouse disinfected'),
                    'floor_mopped' => __('Floor swept and wet-mopped with disinfectant'),
                    'sink_scrubbed' => __('Sink scrubbed and sanitized'),
                    'waste_emptied' => __('Waste bins emptied & relined'),
                ],
                'C' => [
                    'couch_structural' => __('Examination couch structural integrity checked'),
                    'electrical_sockets' => __('Electrical sockets & switch plates tested'),
                    'overhead_lighting' => __('Overhead lighting & exam light illumination checked'),
                    'hvac_verified' => __('HVAC / Fan / AC filter and cooling verified'),
                    'bp_calibration' => __('BP apparatus calibration verified'),
                    'scale_calibration' => __('Weighing scale standard weight calibration'),
                    'it_hardware' => __('IT equipment & printer hardware check'),
                    'furniture_stability' => __('Furniture damage / stability assessment'),
                    'plumbing_drainage' => __('Plumbing & sink drainage inspection'),
                    'sharps_container' => __('Sharps container checked (< 3/4 full)'),
                    'no_dust_debris' => __('Visual check: No dust, debris, or cobwebs'),
                    'curtains_clean' => __('Curtains / blinds visually clean'),
                    'room_odor' => __('Room odor acceptable / ventilated'),
                    'biomed_pm_due' => __('Biomedical preventive maintenance due date reviewed'),
                ],
                default => [],
            },
            EquipmentInspectionArea::LabourRoom => match ($section) {
                'B' => [
                    'delivery_bed_disinfected' => __('Delivery bed washed & high-level disinfected'),
                    'mattress_integrity' => __('Mattress integrity and waterproof cover checked'),
                    'trolley_sanitized' => __('Instrument trolley & procedure surfaces sanitized'),
                    'foot_step_disinfected' => __('Foot step, examination light handle disinfected'),
                    'ultrasound_probe_wiped' => __('Ultrasound probe wiped per manufacturer standard'),
                    'suction_canister' => __('Suction canister replaced / exterior disinfected'),
                    'spills_managed' => __('Blood/body fluid spills managed per IPC protocol'),
                    'soiled_linen_bagged' => __('Soiled linen bagged immediately in yellow/red bags'),
                    'biohazard_cleared' => __('Biohazard waste cleared & sharps container checked'),
                    'floor_mopped' => __('Floor wet-mopped with sodium hypochlorite'),
                    'supplies_restocked' => __('Hand hygiene supplies & delivery packs restocked'),
                ],
                'C' => [
                    'nonessential_off' => __('All non-essential electrical equipment turned off'),
                    'oxygen_pressure' => __('Main and backup oxygen cylinder pressure verified'),
                    'suction_level' => __('Suction unit suction level tested (-100 to -120 mmHg)'),
                    'crash_cart_sealed' => __('Emergency crash cart verified sealed and stocked'),
                    'baby_warmer_ready' => __('Baby warmer functional, pre-heat mode operational'),
                    'neonatal_ambu_ready' => __('Neonatal Ambu bag and laryngoscope ready at bedside'),
                    'delivery_bed_made' => __('Delivery bed made up with fresh sterile drapes'),
                    'emergency_pack' => __('Instrument trolley set up with emergency delivery pack'),
                    'waste_removed' => __('All clinical waste removed to central holding area'),
                    'room_sanitized' => __('Room completely sanitized & ready for immediate emergency'),
                ],
                default => [],
            },
            EquipmentInspectionArea::OperationTheatre => match ($section) {
                'B' => [
                    'ot_floor_clean' => __('OT floor clean & sanitized'),
                    'ot_table_level' => __('OT table operational & level'),
                    'surgical_lights_tested' => __('Surgical lights tested'),
                    'suction_lines' => __('Suction lines verified (-400+ mmHg)'),
                    'anaesthesia_check' => __('Anaesthesia machine check complete'),
                    'ventilator_oxygen' => __('Ventilator & oxygen supply ready'),
                    'cautery_tested' => __('Cautery tested with neutral plate'),
                    'sterile_sets_prepared' => __('Sterile instrument sets prepared'),
                    'airway_cart' => __('Emergency airway cart verified'),
                    'temp_humidity' => __('OT Temp (18-21°C) / Humidity OK'),
                ],
                'C' => [
                    'instruments_transferred' => __('Used instruments safely transferred'),
                    'soiled_linen_removed' => __('Soiled linen removed in closed bags'),
                    'waste_cleared' => __('Biomedical waste cleared & relined'),
                    'table_pads_disinfected' => __('OT table & pads disinfected'),
                    'light_handles_disinfected' => __('Surgical light handles disinfected'),
                    'high_touch_wiped' => __('High-touch surfaces wiped down'),
                    'floor_damp_mopped' => __('Floor damp-mopped (1-meter zone)'),
                    'spills_treated' => __('Fluid spills treated per IPC policy'),
                    'fresh_drapes' => __('Fresh sterile drapes / supplies set up'),
                ],
                'D' => [
                    'table_dismantled' => __('OT table completely dismantled/sanitized'),
                    'lights_pendants_wiped' => __('Surgical lights & pendants wiped'),
                    'equipment_exteriors' => __('All equipment exteriors cleaned per OEM'),
                    'floor_scrub' => __('Full floor scrub with enzymatic detergent'),
                    'walls_doors' => __('Walls, doors & handles disinfected'),
                    'scrub_sink' => __('Scrub sink & splash areas disinfected'),
                    'waste_linen_removed' => __('All waste & linen removed from theatre'),
                    'consumables_restocked' => __('Consumables & emergency drugs restocked'),
                    'air_exchange' => __('Room air exchange time observed'),
                    'equipment_parked' => __('Equipment parked in designated bays'),
                    'defects_logged' => __('Defects logged in Maintenance Register'),
                ],
                default => [],
            },
            EquipmentInspectionArea::Decontamination => match ($section) {
                'B' => [
                    'sink_scrubbed' => __('Sink scrubbed, clean, free of organic residue'),
                    'drainage_smooth' => __('Sink drainage smooth with zero backflow'),
                    'running_water' => __('Continuous running water (hot/cold) verified'),
                    'countertops_disinfected' => __('Countertops and work surfaces disinfected'),
                    'instruments_soaked' => __('Instruments soaked immediately in enzymatic detergent'),
                    'ppe_worn' => __('Full PPE worn by decontamination personnel'),
                    'brushes_clean' => __('Cleaning brushes clean, intact, disinfected daily'),
                    'dirty_to_clean' => __('Strict Dirty-to-Clean workflow direction maintained'),
                    'zones_separated' => __('Cleaned items kept entirely separate from dirty zone'),
                    'sharps_removed' => __('Surgical sharps safely removed prior to manual washing'),
                    'floor_dry' => __('Floor dry, clean, free from standing water or slips'),
                    'walls_splash' => __('Walls and splash zones wiped clean'),
                    'ventilation' => __('Ventilation functional / zero foul chemical odor'),
                    'chemicals_locked' => __('Chemicals stored in locked, labeled cabinets'),
                ],
                'C' => [
                    'taps_descaled' => __('Water taps, spray nozzles, and aerators descaled'),
                    'plumbing_traps' => __('Plumbing traps cleared and inspected for leaks'),
                    'sinks_polished' => __('Stainless steel sinks polished and treated'),
                    'drying_racks_cleaned' => __('Drying racks and storage shelves deep cleaned'),
                    'brushes_replaced' => __('Cleaning brushes replaced or autoclaved'),
                    'ppe_inventory' => __('Heavy-duty PPE inventory inspected and replaced'),
                    'waste_sanitized' => __('Waste containers sanitized and integrity checked'),
                    'signage_verified' => __('Workflow signage and biohazard labels verified'),
                    'detergent_audit' => __('Enzymatic detergent concentration audit performed'),
                    'eyewash_tested' => __('Eyewash emergency station tested and flushed'),
                ],
                default => [],
            },
            EquipmentInspectionArea::MaintenanceRegister => [],
        };
    }

    /**
     * Sign-off fields for an area.
     *
     * Each field: type = yes_no | text | choice (choices listed).
     *
     * @return array<string, array{label: string, type: string, required: bool, choices?: list<string>}>
     */
    public function signOffFields(EquipmentInspectionArea $area): array
    {
        return match ($area) {
            EquipmentInspectionArea::ConsultationRoom => [
                'equip_issues' => ['label' => __('Equip Issues'), 'type' => 'yes_no', 'required' => true],
                'cleaning_done' => ['label' => __('Cleaning Done'), 'type' => 'yes_no', 'required' => true],
                'reported_to' => ['label' => __('Reported To'), 'type' => 'text', 'required' => false],
            ],
            EquipmentInspectionArea::LabourRoom => [
                'equip_defect' => ['label' => __('Equip Defect'), 'type' => 'yes_no', 'required' => true],
                'restocked' => ['label' => __('Restocked'), 'type' => 'yes_no', 'required' => true],
                'emerg_ready' => ['label' => __('Emerg. Ready'), 'type' => 'yes_no', 'required' => true],
            ],
            EquipmentInspectionArea::OperationTheatre => [
                'faults_identified' => ['label' => __('Faults Identified'), 'type' => 'yes_no', 'required' => true],
                'terminal_clean' => [
                    'label' => __('Terminal Clean'),
                    'type' => 'choice',
                    'required' => true,
                    'choices' => ['complete', 'incomplete'],
                ],
                'ot_readiness' => [
                    'label' => __('OT Readiness'),
                    'type' => 'choice',
                    'required' => true,
                    'choices' => ['approved', 'not_approved'],
                ],
                'ot_incharge_name' => ['label' => __('OT In-Charge'), 'type' => 'text', 'required' => false],
            ],
            EquipmentInspectionArea::Decontamination => [
                'biomedical_lead' => ['label' => __('Biomedical Lead'), 'type' => 'text', 'required' => false],
                'ipc_officer' => ['label' => __('IPC Officer'), 'type' => 'text', 'required' => false],
                'biomedical_engineer' => ['label' => __('Biomedical Engineer'), 'type' => 'text', 'required' => false],
                'qa_manager' => ['label' => __('Quality Assurance Manager'), 'type' => 'text', 'required' => false],
            ],
            EquipmentInspectionArea::MaintenanceRegister => [
                'biomedical_lead' => ['label' => __('Biomedical Lead'), 'type' => 'text', 'required' => false],
                'ipc_officer' => ['label' => __('IPC Officer'), 'type' => 'text', 'required' => false],
                'biomedical_engineer' => ['label' => __('Biomedical Engineer'), 'type' => 'text', 'required' => false],
                'qa_manager' => ['label' => __('Quality Assurance Manager'), 'type' => 'text', 'required' => false],
            ],
        };
    }

    /**
     * Checklist section keys that use simple checked answers for an area.
     *
     * @return list<string>
     */
    public function checklistSections(EquipmentInspectionArea $area): array
    {
        return match ($area) {
            EquipmentInspectionArea::OperationTheatre => ['B', 'C', 'D'],
            EquipmentInspectionArea::MaintenanceRegister => [],
            default => ['B', 'C'],
        };
    }

    /**
     * Flatten all item labels for an area (for notifications / admin views).
     *
     * @return array<string, string>
     */
    public function allItemLabels(EquipmentInspectionArea $area): array
    {
        $labels = [];

        foreach ($this->equipmentItems($area) as $key => $item) {
            $labels[$key] = $item['label'];
        }

        foreach ($this->checklistSections($area) as $section) {
            foreach ($this->checklistItems($area, $section) as $key => $label) {
                $labels[$key] = $label;
            }
        }

        return $labels;
    }
}
