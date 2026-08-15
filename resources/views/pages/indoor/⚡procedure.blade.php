<?php

use App\Enums\ProcedureAttachmentType;
use App\Enums\ProcedureMedicationDoseStatus;
use App\Enums\ProcedureNoteStyle;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\Procedure;
use App\Models\ProcedureAttachment;
use App\Models\ProcedureDeliveryNote;
use App\Models\ProcedureDischargeDetail;
use App\Models\ProcedureFetalHeart;
use App\Models\ProcedureMedication;
use App\Models\ProcedureMedicationDose;
use App\Models\ProcedureOperationNote;
use App\Models\ProcedurePostOpOrder;
use App\Models\ProcedurePreOpOrder;
use App\Models\ProcedureProgressNote;
use App\Models\ProcedureVital;
use App\Services\ProcedureMedicationScheduler;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Procedure Chart')] class extends Component
{
    use WithFileUploads;

    public int $procedureId;

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $consentPhotos = [];

    public bool $preOpGiveBath = false;

    public bool $preOpProvideHospitalDress = false;

    public string $preOpNpoFrom = '';

    public bool $preOpMarkOperationSite = false;

    public string $preOpOperationSite = '';

    public bool $preOpShaveAndPrepare = false;

    public string $preOpBloodPints = '';

    public string $preOpInvestigations = '';

    public string $preOpPreMedication = '';

    public string $preOpSendToOtAt = '';

    public string $preOpOtherOrders = '';

    public string $preOpDoneBy = '';

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $preOpPhotos = [];

    public string $vitalRecordedAt = '';

    public string $vitalPulse = '';

    public string $vitalBpSystolic = '';

    public string $vitalBpDiastolic = '';

    public string $vitalRespRate = '';

    public string $vitalTemp = '';

    public string $vitalCvp = '';

    public string $vitalIvFluid = '';

    public string $vitalOralNg = '';

    public string $vitalUrine = '';

    public string $vitalStool = '';

    public string $vitalAspirate = '';

    public string $vitalDrain = '';

    public string $vitalNotes = '';

    public string $fhrRecordedAt = '';

    public string $fhrValue = '';

    public string $fhrNotes = '';

    public string $medForm = 'tab';

    public string $medMedicineId = '';

    public string $medInjectionId = '';

    public string $medDripBaseId = '';

    public string $medCustomName = '';

    public string $medDose = '';

    public string $medRoute = '';

    public string $medScheduleType = 'once_now';

    public string $medScheduleTimesText = '';

    public string $medIntervalHours = '';

    public string $medNotes = '';

    public string $opOperatedOn = '';

    public string $opStartedAt = '';

    public string $opEndedAt = '';

    public string $opOperation = '';

    public string $opSurgeon = '';

    public string $opNurse = '';

    public string $opAnaesthesia = '';

    public string $opFindings = '';

    public string $opProcedureText = '';

    public string $opClosure = '';

    public string $opDrain = '';

    public string $opBiopsy = '';

    public string $dnLabourType = '';

    public string $dnProcedureName = '';

    public string $dnObstetrician = '';

    public string $dnAssistant = '';

    public string $dnDeliveredAt = '';

    public string $dnAnalgesia = '';

    public string $dnDeliveryDetails = '';

    public string $dnLabourFirstStage = '';

    public string $dnLabourSecondStage = '';

    public string $dnLabourThirdStage = '';

    public string $dnComplications = '';

    public string $dnBabySex = '';

    public string $dnBabyWeight = '';

    public string $dnApgarScore = '';

    public string $dnResuscitatedBy = '';

    public bool $postOpMaintainIntakeOutput = false;

    public string $postOpNpoTill = '';

    public string $postOpAntibiotics = '';

    public string $postOpIvFluids = '';

    public string $postOpAnalgesics = '';

    public string $postOpAntiemetics = '';

    public string $postOpBiopsy = '';

    public string $postOpOthers = '';

    public string $postOpDoneBy = '';

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $postOpPhotos = [];

    public string $progressNotedAt = '';

    public string $progressNote = '';

    public string $dischargeBloodGroup = '';

    public string $dischargeIndication = '';

    public string $dischargeProcedureTime = '';

    public string $dischargeParity = '';

    public string $dischargeBabySex = '';

    public string $dischargeBabyWeight = '';

    public string $dischargeBabyCondition = '';

    public string $dischargeRxText = '';

    public string $dischargeStitchRemovalDate = '';

    public string $dischargeOutcomeSummary = '';

    public string $activeTab = 'consent';

    /**
     * Initialize the component with the given procedure.
     */
    public function mount(Procedure $procedure): void
    {
        $this->procedureId = $procedure->id;

        $this->vitalRecordedAt = now()->format('Y-m-d\TH:i');
        $this->fhrRecordedAt = now()->format('Y-m-d\TH:i');
        $this->progressNotedAt = now()->format('Y-m-d\TH:i');

        $this->hydratePreOpForm($procedure);
        $this->hydrateOperationNoteForm($procedure);
        $this->hydrateDeliveryNoteForm($procedure);
        $this->hydratePostOpForm($procedure);
        $this->hydrateDischargeForm($procedure);
    }

    /**
     * Switch the active chart wizard tab.
     */
    public function setActiveTab(string $tab): void
    {
        if (! array_key_exists($tab, $this->chartTabs)) {
            return;
        }

        $this->activeTab = $tab;
    }

    /**
     * Move to the previous chart wizard tab.
     */
    public function previousTab(): void
    {
        $keys = array_keys($this->chartTabs);
        $index = array_search($this->activeTab, $keys, true);

        if ($index === false || $index === 0) {
            return;
        }

        $this->activeTab = $keys[$index - 1];
    }

    /**
     * Move to the next chart wizard tab.
     */
    public function nextTab(): void
    {
        $keys = array_keys($this->chartTabs);
        $index = array_search($this->activeTab, $keys, true);

        if ($index === false || $index >= count($keys) - 1) {
            return;
        }

        $this->activeTab = $keys[$index + 1];
    }

    /**
     * Get the procedure with all clinical relations loaded.
     */
    #[Computed]
    public function procedure(): Procedure
    {
        return Procedure::query()
            ->with([
                'patient.family',
                'procedureType',
                'doctor',
                'room',
                'attachments' => fn ($query) => $query->latest(),
                'attachments.uploader',
                'vitals' => fn ($query) => $query->latest('recorded_at')->limit(30),
                'vitals.recorder',
                'fetalHearts' => fn ($query) => $query->latest('recorded_at')->limit(30),
                'fetalHearts.recorder',
                'preOpOrder.completedBy',
                'postOpOrder.completedBy',
                'operationNote.recordedBy',
                'deliveryNote.recordedBy',
                'progressNotes' => fn ($query) => $query->latest('noted_at'),
                'progressNotes.doctorUser',
                'medications' => fn ($query) => $query->latest(),
                'medications.doses' => fn ($query) => $query->orderBy('due_at'),
                'medications.medicine',
                'medications.injection',
                'medications.dripBase',
                'medications.prescriber',
                'dischargeDetail',
            ])
            ->findOrFail($this->procedureId);
    }

    /**
     * Get the note style for the procedure's type (operation vs. delivery).
     */
    #[Computed]
    public function noteStyle(): ProcedureNoteStyle
    {
        return $this->procedure->procedureType?->note_style ?? ProcedureNoteStyle::Operation;
    }

    /**
     * Determine whether this procedure requires fetal heart rate tracking.
     */
    #[Computed]
    public function requiresFetalHeart(): bool
    {
        return (bool) $this->procedure->procedureType?->requires_fetal_heart;
    }

    /**
     * Determine whether this procedure requires a birth certificate.
     */
    #[Computed]
    public function requiresBirthCertificate(): bool
    {
        return (bool) $this->procedure->procedureType?->requires_birth_certificate;
    }

    /**
     * Determine whether the chart should be treated as read-only.
     */
    #[Computed]
    public function isReadOnly(): bool
    {
        return ! $this->procedure->isAdmitted() || $this->procedure->isDischarged();
    }

    /**
     * Get the ordered chart wizard tabs for this procedure.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function chartTabs(): array
    {
        $tabs = [
            'consent' => __('Consent'),
            'pre-op' => __('Pre-op'),
            'vitals' => __('Vitals'),
        ];

        if ($this->requiresFetalHeart) {
            $tabs['fhr'] = __('FHR');
        }

        $tabs['medications'] = __('Medications');
        $tabs['note'] = $this->noteStyle === ProcedureNoteStyle::Delivery
            ? __('Delivery Note')
            : __('Operation Note');
        $tabs['post-op'] = __('Post-op');
        $tabs['progress'] = __('Progress Notes');
        $tabs['discharge'] = __('Discharge');

        return $tabs;
    }

    /**
     * Get the zero-based index of the active chart tab.
     */
    #[Computed]
    public function activeTabIndex(): int
    {
        $index = array_search($this->activeTab, array_keys($this->chartTabs), true);

        return $index === false ? 0 : $index;
    }

    /**
     * Determine whether the active tab is the first wizard step.
     */
    #[Computed]
    public function isFirstTab(): bool
    {
        return $this->activeTabIndex === 0;
    }

    /**
     * Determine whether the active tab is the last wizard step.
     */
    #[Computed]
    public function isLastTab(): bool
    {
        return $this->activeTabIndex === count($this->chartTabs) - 1;
    }

    /**
     * Get the active medicine catalog for prescribing.
     *
     * @return Collection<int, Medicine>
     */
    #[Computed]
    public function medicines(): Collection
    {
        return Medicine::query()->active()->orderBy('name')->get();
    }

    /**
     * Get the active injection catalog for prescribing.
     *
     * @return Collection<int, Injection>
     */
    #[Computed]
    public function injections(): Collection
    {
        return Injection::query()->active()->orderBy('name')->get();
    }

    /**
     * Get the active drip base catalog for prescribing.
     *
     * @return Collection<int, DripBase>
     */
    #[Computed]
    public function dripBases(): Collection
    {
        return DripBase::query()->active()->orderBy('name')->get();
    }

    /**
     * Clear catalog selections when the medication form type changes.
     */
    public function updatedMedForm(): void
    {
        $this->medMedicineId = '';
        $this->medInjectionId = '';
        $this->medDripBaseId = '';
    }

    /**
     * Upload the selected consent photos for this procedure.
     */
    public function saveConsentPhotos(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $this->validate([
            'consentPhotos' => ['required', 'array', 'min:1'],
            'consentPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        foreach ($this->consentPhotos as $photo) {
            $this->storeAttachment($photo, ProcedureAttachmentType::Consent);
        }

        $this->consentPhotos = [];
        $this->resetValidation();
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Consent photo(s) uploaded.'));
    }

    /**
     * Mark the consent step as complete.
     */
    public function markConsentComplete(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $consentPhotoCount = $this->procedure->attachments
            ->where('type', ProcedureAttachmentType::Consent)
            ->count();

        if ($consentPhotoCount < 2) {
            Flux::toast(variant: 'danger', text: __('Upload at least 2 consent photos before marking consent complete.'));

            return;
        }

        $this->procedure->update(['consent_completed_at' => now()]);
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Consent marked complete.'));
    }

    /**
     * Delete an attachment belonging to this procedure.
     */
    public function deleteAttachment(int $attachmentId): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $attachment = ProcedureAttachment::where('procedure_id', $this->procedureId)->findOrFail($attachmentId);
        $attachment->delete();

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
    }

    /**
     * Save (create or update) the pre-op order for this procedure.
     */
    public function savePreOpOrder(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate($this->preOpRules());

        $order = $this->procedure->preOpOrder ?? new ProcedurePreOpOrder(['procedure_id' => $this->procedureId]);
        $order->fill([
            'give_bath' => $validated['preOpGiveBath'],
            'provide_hospital_dress' => $validated['preOpProvideHospitalDress'],
            'npo_from' => $validated['preOpNpoFrom'] ?: null,
            'mark_operation_site' => $validated['preOpMarkOperationSite'],
            'operation_site' => $validated['preOpOperationSite'] ?: null,
            'shave_and_prepare' => $validated['preOpShaveAndPrepare'],
            'blood_pints' => $validated['preOpBloodPints'] !== '' ? (int) $validated['preOpBloodPints'] : null,
            'investigations' => $validated['preOpInvestigations'] ?: null,
            'pre_medication' => $validated['preOpPreMedication'] ?: null,
            'send_to_ot_at' => $validated['preOpSendToOtAt'] ?: null,
            'other_orders' => $validated['preOpOtherOrders'] ?: null,
        ]);
        $order->save();

        if ($this->preOpPhotos !== []) {
            foreach ($this->preOpPhotos as $photo) {
                $this->storeAttachment($photo, ProcedureAttachmentType::PreOp);
            }
            $this->preOpPhotos = [];
        }

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Pre-op orders saved.'));
    }

    /**
     * Mark the pre-op order as complete and stamp the procedure record.
     */
    public function completePreOpOrder(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate(['preOpDoneBy' => ['required', 'string', 'max:255']]);

        if ($this->procedure->preOpOrder === null) {
            Flux::toast(variant: 'danger', text: __('Save the pre-op order before marking it complete.'));

            return;
        }

        $this->procedure->preOpOrder->update([
            'done_by' => $validated['preOpDoneBy'],
            'completed_by' => auth()->id(),
            'completed_at' => now(),
        ]);

        $this->procedure->update([
            'pre_op_completed_at' => now(),
            'pre_op_done_by' => $validated['preOpDoneBy'],
            'pre_op_completed_by' => auth()->id(),
        ]);

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Pre-op marked complete.'));
    }

    /**
     * Record a new set of vitals for this procedure.
     */
    public function saveVital(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate($this->vitalRules());

        ProcedureVital::create([
            'procedure_id' => $this->procedureId,
            'recorded_at' => $validated['vitalRecordedAt'],
            'pulse' => $validated['vitalPulse'] !== '' ? (int) $validated['vitalPulse'] : null,
            'bp_systolic' => $validated['vitalBpSystolic'] !== '' ? (int) $validated['vitalBpSystolic'] : null,
            'bp_diastolic' => $validated['vitalBpDiastolic'] !== '' ? (int) $validated['vitalBpDiastolic'] : null,
            'resp_rate' => $validated['vitalRespRate'] !== '' ? (int) $validated['vitalRespRate'] : null,
            'temp' => $validated['vitalTemp'] !== '' ? (float) $validated['vitalTemp'] : null,
            'cvp' => $validated['vitalCvp'] ?: null,
            'iv_fluid' => $validated['vitalIvFluid'] ?: null,
            'oral_ng' => $validated['vitalOralNg'] ?: null,
            'urine' => $validated['vitalUrine'] ?: null,
            'stool' => $validated['vitalStool'] ?: null,
            'aspirate' => $validated['vitalAspirate'] ?: null,
            'drain' => $validated['vitalDrain'] ?: null,
            'notes' => $validated['vitalNotes'] ?: null,
            'recorded_by' => auth()->id(),
        ]);

        $this->resetVitalsForm();
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Vitals recorded.'));
    }

    /**
     * Record a new fetal heart rate reading for this procedure.
     */
    public function saveFetalHeart(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate([
            'fhrRecordedAt' => ['required', 'date'],
            'fhrValue' => ['required', 'integer', 'min:0', 'max:300'],
            'fhrNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        ProcedureFetalHeart::create([
            'procedure_id' => $this->procedureId,
            'recorded_at' => $validated['fhrRecordedAt'],
            'fhr' => (int) $validated['fhrValue'],
            'notes' => $validated['fhrNotes'] ?: null,
            'recorded_by' => auth()->id(),
        ]);

        $this->fhrRecordedAt = now()->format('Y-m-d\TH:i');
        $this->fhrValue = '';
        $this->fhrNotes = '';
        $this->resetValidation();
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Fetal heart rate recorded.'));
    }

    /**
     * Prescribe a new medication and materialize its dose schedule.
     */
    public function prescribeMedication(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate([
            'medForm' => ['required', Rule::in(['tab', 'inj', 'drip'])],
            'medMedicineId' => ['nullable', 'integer', 'exists:medicines,id'],
            'medInjectionId' => ['nullable', 'integer', 'exists:injections,id'],
            'medDripBaseId' => ['nullable', 'integer', 'exists:drip_bases,id'],
            'medCustomName' => ['nullable', 'string', 'max:255'],
            'medDose' => ['required', 'string', 'max:255'],
            'medRoute' => ['nullable', 'string', 'max:255'],
            'medScheduleType' => ['required', Rule::in(['once_now', 'once_at', 'every_hour', 'now_and_at', 'at_times'])],
            'medScheduleTimesText' => ['nullable', 'string', 'max:255'],
            'medIntervalHours' => ['nullable', 'integer', 'min:1', 'max:24'],
            'medNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $catalogId = match ($validated['medForm']) {
            'tab' => $validated['medMedicineId'] !== null && $validated['medMedicineId'] !== '' ? (int) $validated['medMedicineId'] : null,
            'inj' => $validated['medInjectionId'] !== null && $validated['medInjectionId'] !== '' ? (int) $validated['medInjectionId'] : null,
            'drip' => $validated['medDripBaseId'] !== null && $validated['medDripBaseId'] !== '' ? (int) $validated['medDripBaseId'] : null,
            default => null,
        };

        if (blank($catalogId) && blank($validated['medCustomName'])) {
            $this->addError('medCustomName', __('Select a catalog item or enter a custom name.'));

            return;
        }

        $scheduleTimes = collect(explode(',', $validated['medScheduleTimesText'] ?? ''))
            ->map(fn ($time) => trim($time))
            ->filter()
            ->values();

        if (in_array($validated['medScheduleType'], ['once_at', 'now_and_at', 'at_times'], true) && $scheduleTimes->isEmpty()) {
            $this->addError('medScheduleTimesText', __('Enter at least one time in HH:MM format.'));

            return;
        }

        if ($validated['medScheduleType'] === 'every_hour' && blank($validated['medIntervalHours'])) {
            $this->addError('medIntervalHours', __('Interval hours is required for an every-hour schedule.'));

            return;
        }

        $medication = ProcedureMedication::create([
            'procedure_id' => $this->procedureId,
            'form' => $validated['medForm'],
            'medicine_id' => $validated['medForm'] === 'tab' ? $catalogId : null,
            'injection_id' => $validated['medForm'] === 'inj' ? $catalogId : null,
            'drip_base_id' => $validated['medForm'] === 'drip' ? $catalogId : null,
            'custom_name' => $validated['medCustomName'] ?: null,
            'dose' => $validated['medDose'],
            'route' => $validated['medRoute'] ?: null,
            'notes' => $validated['medNotes'] ?: null,
            'schedule_type' => $validated['medScheduleType'],
            'schedule_times' => $scheduleTimes->isNotEmpty() ? $scheduleTimes->all() : null,
            'interval_hours' => $validated['medScheduleType'] === 'every_hour' ? (int) $validated['medIntervalHours'] : null,
            'starts_at' => now(),
            'status' => 'active',
            'prescribed_by' => auth()->id(),
        ]);

        app(ProcedureMedicationScheduler::class)->materialize($medication);

        $this->resetMedicationForm();
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Medication prescribed.'));
    }

    /**
     * Mark a single medication dose as given by the current user.
     */
    public function markDoseGiven(int $doseId): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $dose = ProcedureMedicationDose::query()
            ->whereHas('medication', fn ($query) => $query->where('procedure_id', $this->procedureId))
            ->findOrFail($doseId);

        $dose->update([
            'status' => ProcedureMedicationDoseStatus::Given,
            'given_at' => now(),
            'given_by' => auth()->id(),
        ]);

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Dose marked as given.'));
    }

    /**
     * Mark a single medication dose as skipped.
     */
    public function markDoseSkipped(int $doseId): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $dose = ProcedureMedicationDose::query()
            ->whereHas('medication', fn ($query) => $query->where('procedure_id', $this->procedureId))
            ->findOrFail($doseId);

        $dose->update([
            'status' => ProcedureMedicationDoseStatus::Skipped,
            'given_at' => now(),
            'given_by' => auth()->id(),
        ]);

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Dose marked as skipped.'));
    }

    /**
     * Save (create or update) the operation note for this procedure.
     */
    public function saveOperationNote(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate([
            'opOperatedOn' => ['nullable', 'date'],
            'opStartedAt' => ['nullable', 'string', 'max:5'],
            'opEndedAt' => ['nullable', 'string', 'max:5'],
            'opOperation' => ['nullable', 'string', 'max:255'],
            'opSurgeon' => ['nullable', 'string', 'max:255'],
            'opNurse' => ['nullable', 'string', 'max:255'],
            'opAnaesthesia' => ['nullable', 'string', 'max:255'],
            'opFindings' => ['nullable', 'string', 'max:4000'],
            'opProcedureText' => ['nullable', 'string', 'max:4000'],
            'opClosure' => ['nullable', 'string', 'max:2000'],
            'opDrain' => ['nullable', 'string', 'max:2000'],
            'opBiopsy' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = $this->procedure->operationNote ?? new ProcedureOperationNote(['procedure_id' => $this->procedureId]);
        $note->fill([
            'operated_on' => $validated['opOperatedOn'] ?: null,
            'started_at' => $validated['opStartedAt'] ?: null,
            'ended_at' => $validated['opEndedAt'] ?: null,
            'operation' => $validated['opOperation'] ?: null,
            'surgeon' => $validated['opSurgeon'] ?: null,
            'nurse' => $validated['opNurse'] ?: null,
            'anaesthesia' => $validated['opAnaesthesia'] ?: null,
            'findings' => $validated['opFindings'] ?: null,
            'procedure_text' => $validated['opProcedureText'] ?: null,
            'closure' => $validated['opClosure'] ?: null,
            'drain' => $validated['opDrain'] ?: null,
            'biopsy' => $validated['opBiopsy'] ?: null,
            'recorded_by' => auth()->id(),
        ]);
        $note->save();

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Operation note saved.'));
    }

    /**
     * Save (create or update) the delivery note for this procedure.
     */
    public function saveDeliveryNote(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate([
            'dnLabourType' => ['nullable', 'string', 'max:255'],
            'dnProcedureName' => ['nullable', 'string', 'max:255'],
            'dnObstetrician' => ['nullable', 'string', 'max:255'],
            'dnAssistant' => ['nullable', 'string', 'max:255'],
            'dnDeliveredAt' => ['nullable', 'date'],
            'dnAnalgesia' => ['nullable', 'string', 'max:255'],
            'dnDeliveryDetails' => ['nullable', 'string', 'max:4000'],
            'dnLabourFirstStage' => ['nullable', 'string', 'max:255'],
            'dnLabourSecondStage' => ['nullable', 'string', 'max:255'],
            'dnLabourThirdStage' => ['nullable', 'string', 'max:255'],
            'dnComplications' => ['nullable', 'string', 'max:2000'],
            'dnBabySex' => ['nullable', 'string', 'max:20'],
            'dnBabyWeight' => ['nullable', 'string', 'max:50'],
            'dnApgarScore' => ['nullable', 'string', 'max:50'],
            'dnResuscitatedBy' => ['nullable', 'string', 'max:255'],
        ]);

        $note = $this->procedure->deliveryNote ?? new ProcedureDeliveryNote(['procedure_id' => $this->procedureId]);
        $note->fill([
            'labour_type' => $validated['dnLabourType'] ?: null,
            'procedure_name' => $validated['dnProcedureName'] ?: null,
            'obstetrician' => $validated['dnObstetrician'] ?: null,
            'assistant' => $validated['dnAssistant'] ?: null,
            'delivered_at' => $validated['dnDeliveredAt'] ?: null,
            'analgesia' => $validated['dnAnalgesia'] ?: null,
            'delivery_details' => $validated['dnDeliveryDetails'] ?: null,
            'labour_first_stage' => $validated['dnLabourFirstStage'] ?: null,
            'labour_second_stage' => $validated['dnLabourSecondStage'] ?: null,
            'labour_third_stage' => $validated['dnLabourThirdStage'] ?: null,
            'complications' => $validated['dnComplications'] ?: null,
            'baby_sex' => $validated['dnBabySex'] ?: null,
            'baby_weight' => $validated['dnBabyWeight'] ?: null,
            'apgar_score' => $validated['dnApgarScore'] ?: null,
            'resuscitated_by' => $validated['dnResuscitatedBy'] ?: null,
            'recorded_by' => auth()->id(),
        ]);
        $note->save();

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Delivery note saved.'));
    }

    /**
     * Mark the operation/procedure as started.
     */
    public function markOperationStarted(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $this->procedure->update(['operation_started_at' => now()]);
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Operation marked as started.'));
    }

    /**
     * Mark the operation/procedure as completed.
     */
    public function markOperationCompleted(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $this->procedure->update(['operation_completed_at' => now()]);
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Operation marked as completed.'));
    }

    /**
     * Save (create or update) the post-op order for this procedure.
     */
    public function savePostOpOrder(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate([
            'postOpMaintainIntakeOutput' => ['boolean'],
            'postOpNpoTill' => ['nullable', 'date'],
            'postOpAntibiotics' => ['nullable', 'string', 'max:2000'],
            'postOpIvFluids' => ['nullable', 'string', 'max:2000'],
            'postOpAnalgesics' => ['nullable', 'string', 'max:2000'],
            'postOpAntiemetics' => ['nullable', 'string', 'max:2000'],
            'postOpBiopsy' => ['nullable', 'string', 'max:2000'],
            'postOpOthers' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = $this->procedure->postOpOrder ?? new ProcedurePostOpOrder(['procedure_id' => $this->procedureId]);
        $order->fill([
            'maintain_intake_output' => $validated['postOpMaintainIntakeOutput'],
            'npo_till' => $validated['postOpNpoTill'] ?: null,
            'antibiotics' => $validated['postOpAntibiotics'] ?: null,
            'iv_fluids' => $validated['postOpIvFluids'] ?: null,
            'analgesics' => $validated['postOpAnalgesics'] ?: null,
            'antiemetics' => $validated['postOpAntiemetics'] ?: null,
            'biopsy' => $validated['postOpBiopsy'] ?: null,
            'others' => $validated['postOpOthers'] ?: null,
        ]);
        $order->save();

        if ($this->postOpPhotos !== []) {
            foreach ($this->postOpPhotos as $photo) {
                $this->storeAttachment($photo, ProcedureAttachmentType::PostOp);
            }
            $this->postOpPhotos = [];
        }

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Post-op orders saved.'));
    }

    /**
     * Mark the post-op order as complete and stamp the procedure record.
     */
    public function completePostOpOrder(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate(['postOpDoneBy' => ['required', 'string', 'max:255']]);

        if ($this->procedure->postOpOrder === null) {
            Flux::toast(variant: 'danger', text: __('Save the post-op order before marking it complete.'));

            return;
        }

        $this->procedure->postOpOrder->update([
            'done_by' => $validated['postOpDoneBy'],
            'completed_by' => auth()->id(),
            'completed_at' => now(),
        ]);

        $this->procedure->update([
            'post_op_completed_at' => now(),
            'post_op_completed_by' => auth()->id(),
        ]);

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Post-op marked complete.'));
    }

    /**
     * Add a new progress note for this procedure.
     */
    public function addProgressNote(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate([
            'progressNotedAt' => ['required', 'date'],
            'progressNote' => ['required', 'string', 'max:4000'],
        ]);

        ProcedureProgressNote::create([
            'procedure_id' => $this->procedureId,
            'noted_at' => $validated['progressNotedAt'],
            'note' => $validated['progressNote'],
            'doctor_user_id' => auth()->id(),
        ]);

        $this->progressNotedAt = now()->format('Y-m-d\TH:i');
        $this->progressNote = '';
        $this->resetValidation();
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Progress note added.'));
    }

    /**
     * Save the discharge details without discharging the patient.
     */
    public function saveDischargeDetail(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate($this->dischargeRules());
        $this->persistDischargeDetail($validated);
        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Discharge details saved.'));
    }

    /**
     * Save the discharge details and discharge the patient.
     */
    public function dischargePatient(): void
    {
        if (! $this->ensureEditable()) {
            return;
        }

        $validated = $this->validate($this->dischargeRules());
        $this->persistDischargeDetail($validated);

        $this->procedure->update([
            'discharged_at' => now(),
            'discharged_by' => auth()->id(),
        ]);

        unset($this->procedure);

        Flux::toast(variant: 'success', text: __('Patient discharged.'));
    }

    /**
     * Store an uploaded file as a procedure attachment.
     */
    private function storeAttachment($file, ProcedureAttachmentType $type): void
    {
        $path = $file->store("procedures/{$this->procedureId}/{$type->value}", 'local');

        ProcedureAttachment::create([
            'procedure_id' => $this->procedureId,
            'type' => $type,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => auth()->id(),
        ]);
    }

    /**
     * Persist the discharge detail fields from validated input.
     *
     * @param  array<string, mixed>  $validated
     */
    private function persistDischargeDetail(array $validated): void
    {
        $detail = $this->procedure->dischargeDetail ?? new ProcedureDischargeDetail(['procedure_id' => $this->procedureId]);
        $detail->fill([
            'blood_group' => $validated['dischargeBloodGroup'] ?: null,
            'indication' => $validated['dischargeIndication'] ?: null,
            'procedure_time' => $validated['dischargeProcedureTime'] ?: null,
            'parity' => $validated['dischargeParity'] ?: null,
            'baby_sex' => $validated['dischargeBabySex'] ?: null,
            'baby_weight' => $validated['dischargeBabyWeight'] ?: null,
            'baby_condition' => $validated['dischargeBabyCondition'] ?: null,
            'rx_text' => $validated['dischargeRxText'] ?: null,
            'stitch_removal_date' => $validated['dischargeStitchRemovalDate'] ?: null,
            'outcome_summary' => $validated['dischargeOutcomeSummary'] ?: null,
        ]);
        $detail->save();
    }

    /**
     * Guard against editing a procedure that is not currently admitted.
     */
    private function ensureEditable(): bool
    {
        if ($this->isReadOnly) {
            Flux::toast(variant: 'danger', text: __('This procedure is read-only and cannot be edited.'));

            return false;
        }

        return true;
    }

    /**
     * Reset the vitals form back to its defaults.
     */
    private function resetVitalsForm(): void
    {
        $this->vitalRecordedAt = now()->format('Y-m-d\TH:i');
        $this->vitalPulse = '';
        $this->vitalBpSystolic = '';
        $this->vitalBpDiastolic = '';
        $this->vitalRespRate = '';
        $this->vitalTemp = '';
        $this->vitalCvp = '';
        $this->vitalIvFluid = '';
        $this->vitalOralNg = '';
        $this->vitalUrine = '';
        $this->vitalStool = '';
        $this->vitalAspirate = '';
        $this->vitalDrain = '';
        $this->vitalNotes = '';
        $this->resetValidation();
    }

    /**
     * Reset the medication prescribing form back to its defaults.
     */
    private function resetMedicationForm(): void
    {
        $this->medForm = 'tab';
        $this->medMedicineId = '';
        $this->medInjectionId = '';
        $this->medDripBaseId = '';
        $this->medCustomName = '';
        $this->medDose = '';
        $this->medRoute = '';
        $this->medScheduleType = 'once_now';
        $this->medScheduleTimesText = '';
        $this->medIntervalHours = '';
        $this->medNotes = '';
        $this->resetValidation();
    }

    /**
     * @return array<string, mixed>
     */
    private function preOpRules(): array
    {
        return [
            'preOpGiveBath' => ['boolean'],
            'preOpProvideHospitalDress' => ['boolean'],
            'preOpNpoFrom' => ['nullable', 'date'],
            'preOpMarkOperationSite' => ['boolean'],
            'preOpOperationSite' => ['nullable', 'string', 'max:255'],
            'preOpShaveAndPrepare' => ['boolean'],
            'preOpBloodPints' => ['nullable', 'integer', 'min:0', 'max:20'],
            'preOpInvestigations' => ['nullable', 'string', 'max:2000'],
            'preOpPreMedication' => ['nullable', 'string', 'max:2000'],
            'preOpSendToOtAt' => ['nullable', 'date'],
            'preOpOtherOrders' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vitalRules(): array
    {
        return [
            'vitalRecordedAt' => ['required', 'date'],
            'vitalPulse' => ['nullable', 'integer', 'min:0', 'max:300'],
            'vitalBpSystolic' => ['nullable', 'integer', 'min:0', 'max:300'],
            'vitalBpDiastolic' => ['nullable', 'integer', 'min:0', 'max:300'],
            'vitalRespRate' => ['nullable', 'integer', 'min:0', 'max:150'],
            'vitalTemp' => ['nullable', 'numeric', 'min:80', 'max:110'],
            'vitalCvp' => ['nullable', 'string', 'max:50'],
            'vitalIvFluid' => ['nullable', 'string', 'max:255'],
            'vitalOralNg' => ['nullable', 'string', 'max:255'],
            'vitalUrine' => ['nullable', 'string', 'max:255'],
            'vitalStool' => ['nullable', 'string', 'max:255'],
            'vitalAspirate' => ['nullable', 'string', 'max:255'],
            'vitalDrain' => ['nullable', 'string', 'max:255'],
            'vitalNotes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dischargeRules(): array
    {
        return [
            'dischargeBloodGroup' => ['nullable', 'string', 'max:10'],
            'dischargeIndication' => ['nullable', 'string', 'max:500'],
            'dischargeProcedureTime' => ['nullable', 'date'],
            'dischargeParity' => ['nullable', 'string', 'max:50'],
            'dischargeBabySex' => ['nullable', 'string', 'max:20'],
            'dischargeBabyWeight' => ['nullable', 'string', 'max:50'],
            'dischargeBabyCondition' => ['nullable', 'string', 'max:255'],
            'dischargeRxText' => ['nullable', 'string', 'max:4000'],
            'dischargeStitchRemovalDate' => ['nullable', 'date'],
            'dischargeOutcomeSummary' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * Hydrate the pre-op form fields from an existing order, if any.
     */
    private function hydratePreOpForm(Procedure $procedure): void
    {
        $order = $procedure->preOpOrder;

        $this->preOpGiveBath = (bool) ($order?->give_bath ?? false);
        $this->preOpProvideHospitalDress = (bool) ($order?->provide_hospital_dress ?? false);
        $this->preOpNpoFrom = $order?->npo_from?->format('Y-m-d\TH:i') ?? '';
        $this->preOpMarkOperationSite = (bool) ($order?->mark_operation_site ?? false);
        $this->preOpOperationSite = $order?->operation_site ?? '';
        $this->preOpShaveAndPrepare = (bool) ($order?->shave_and_prepare ?? false);
        $this->preOpBloodPints = $order?->blood_pints !== null ? (string) $order->blood_pints : '';
        $this->preOpInvestigations = $order?->investigations ?? '';
        $this->preOpPreMedication = $order?->pre_medication ?? '';
        $this->preOpSendToOtAt = $order?->send_to_ot_at?->format('Y-m-d\TH:i') ?? '';
        $this->preOpOtherOrders = $order?->other_orders ?? '';
        $this->preOpDoneBy = $order?->done_by ?? '';
    }

    /**
     * Hydrate the operation note form fields from an existing note, if any.
     */
    private function hydrateOperationNoteForm(Procedure $procedure): void
    {
        $note = $procedure->operationNote;

        $this->opOperatedOn = $note?->operated_on?->format('Y-m-d') ?? '';
        $this->opStartedAt = filled($note?->started_at) ? substr((string) $note->started_at, 0, 5) : '';
        $this->opEndedAt = filled($note?->ended_at) ? substr((string) $note->ended_at, 0, 5) : '';
        $this->opOperation = $note?->operation ?? '';
        $this->opSurgeon = $note?->surgeon ?? '';
        $this->opNurse = $note?->nurse ?? '';
        $this->opAnaesthesia = $note?->anaesthesia ?? '';
        $this->opFindings = $note?->findings ?? '';
        $this->opProcedureText = $note?->procedure_text ?? '';
        $this->opClosure = $note?->closure ?? '';
        $this->opDrain = $note?->drain ?? '';
        $this->opBiopsy = $note?->biopsy ?? '';
    }

    /**
     * Hydrate the delivery note form fields from an existing note, if any.
     */
    private function hydrateDeliveryNoteForm(Procedure $procedure): void
    {
        $note = $procedure->deliveryNote;

        $this->dnLabourType = $note?->labour_type ?? '';
        $this->dnProcedureName = $note?->procedure_name ?? '';
        $this->dnObstetrician = $note?->obstetrician ?? '';
        $this->dnAssistant = $note?->assistant ?? '';
        $this->dnDeliveredAt = $note?->delivered_at?->format('Y-m-d\TH:i') ?? '';
        $this->dnAnalgesia = $note?->analgesia ?? '';
        $this->dnDeliveryDetails = $note?->delivery_details ?? '';
        $this->dnLabourFirstStage = $note?->labour_first_stage ?? '';
        $this->dnLabourSecondStage = $note?->labour_second_stage ?? '';
        $this->dnLabourThirdStage = $note?->labour_third_stage ?? '';
        $this->dnComplications = $note?->complications ?? '';
        $this->dnBabySex = $note?->baby_sex ?? '';
        $this->dnBabyWeight = $note?->baby_weight ?? '';
        $this->dnApgarScore = $note?->apgar_score ?? '';
        $this->dnResuscitatedBy = $note?->resuscitated_by ?? '';
    }

    /**
     * Hydrate the post-op form fields from an existing order, if any.
     */
    private function hydratePostOpForm(Procedure $procedure): void
    {
        $order = $procedure->postOpOrder;

        $this->postOpMaintainIntakeOutput = (bool) ($order?->maintain_intake_output ?? false);
        $this->postOpNpoTill = $order?->npo_till?->format('Y-m-d\TH:i') ?? '';
        $this->postOpAntibiotics = $order?->antibiotics ?? '';
        $this->postOpIvFluids = $order?->iv_fluids ?? '';
        $this->postOpAnalgesics = $order?->analgesics ?? '';
        $this->postOpAntiemetics = $order?->antiemetics ?? '';
        $this->postOpBiopsy = $order?->biopsy ?? '';
        $this->postOpOthers = $order?->others ?? '';
        $this->postOpDoneBy = $order?->done_by ?? '';
    }

    /**
     * Hydrate the discharge form fields from existing details, if any.
     */
    private function hydrateDischargeForm(Procedure $procedure): void
    {
        $detail = $procedure->dischargeDetail;

        $this->dischargeBloodGroup = $detail?->blood_group ?? '';
        $this->dischargeIndication = $detail?->indication ?? '';
        $this->dischargeProcedureTime = $detail?->procedure_time?->format('Y-m-d\TH:i') ?? '';
        $this->dischargeParity = $detail?->parity ?? '';
        $this->dischargeBabySex = $detail?->baby_sex ?? '';
        $this->dischargeBabyWeight = $detail?->baby_weight ?? '';
        $this->dischargeBabyCondition = $detail?->baby_condition ?? '';
        $this->dischargeRxText = $detail?->rx_text ?? '';
        $this->dischargeStitchRemovalDate = $detail?->stitch_removal_date?->format('Y-m-d') ?? '';
        $this->dischargeOutcomeSummary = $detail?->outcome_summary ?? '';
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        {{-- A) Header --}}
        <flux:card>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('indoor.ward')" wire:navigate />
                        <flux:heading level="1">{{ $this->procedure->patient->name }}</flux:heading>
                    </div>
                    <flux:text class="mt-1 text-zinc-500">
                        {{ $this->procedure->patient->mrn ?? __('No MRN') }}
                        &middot; {{ __('Room') }} {{ $this->procedure->room?->number ?? $this->procedure->room_number ?? '-' }}
                        &middot; {{ $this->procedure->procedureType?->name ?? $this->procedure->name }}
                        &middot; {{ __('Doctor') }}: {{ $this->procedure->doctor?->name ?? '-' }}
                    </flux:text>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @php($patientBalance = $this->procedure->balance())
                    <flux:badge size="lg" color="{{ $patientBalance > 0 ? 'amber' : 'green' }}">
                        {{ __('Balance') }}: {{ number_format($patientBalance, 2) }}
                    </flux:badge>
                    <flux:button size="sm" variant="ghost" icon="printer" :href="route('indoor.procedures.print', $this->procedure)" target="_blank">
                        {{ __('Bill') }}
                    </flux:button>
                    @if ($this->procedure->isDischarged())
                        <flux:button size="sm" variant="ghost" icon="document-text" :href="route('indoor.procedures.discharge-certificate', $this->procedure)" target="_blank">
                            {{ __('Discharge Cert.') }}
                        </flux:button>
                        @if ($this->requiresBirthCertificate)
                            <flux:button size="sm" variant="ghost" icon="document-text" :href="route('indoor.procedures.birth-certificate', $this->procedure)" target="_blank">
                                {{ __('Birth Cert.') }}
                            </flux:button>
                        @endif
                    @endif
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                <flux:badge size="sm" color="{{ $this->procedure->isAdmitted() ? 'blue' : 'zinc' }}">{{ __('Admitted') }}</flux:badge>
                <flux:badge size="sm" color="{{ $this->procedure->consent_completed_at ? 'green' : 'zinc' }}">{{ __('Consent') }}</flux:badge>
                <flux:badge size="sm" color="{{ $this->procedure->pre_op_completed_at ? 'green' : 'zinc' }}">{{ __('Pre-op') }}</flux:badge>
                <flux:badge size="sm" color="{{ $this->procedure->operation_completed_at ? 'green' : ($this->procedure->operation_started_at ? 'amber' : 'zinc') }}">
                    {{ $this->noteStyle === \App\Enums\ProcedureNoteStyle::Delivery ? __('Delivery') : __('Operation') }}
                </flux:badge>
                <flux:badge size="sm" color="{{ $this->procedure->post_op_completed_at ? 'green' : 'zinc' }}">{{ __('Post-op') }}</flux:badge>
                <flux:badge size="sm" color="{{ $this->procedure->isDischarged() ? 'green' : 'zinc' }}">{{ __('Discharged') }}</flux:badge>

                @if ($this->procedure->isVitalsOverdue())
                    <flux:badge size="sm" color="red">{{ __('Vitals overdue') }}</flux:badge>
                @endif
                @if ($this->requiresFetalHeart && $this->procedure->isFetalHeartOverdue())
                    <flux:badge size="sm" color="red">{{ __('FHR overdue') }}</flux:badge>
                @endif

                @if ($this->isReadOnly)
                    <flux:badge size="sm" color="zinc">{{ __('Read-only') }}</flux:badge>
                @endif
            </div>
        </flux:card>

        {{-- Wizard tabs --}}
        <div class="overflow-x-auto">
            <div class="flex min-w-max gap-1 border-b border-zinc-200 dark:border-zinc-700" role="tablist">
                @foreach ($this->chartTabs as $tabKey => $tabLabel)
                    <button
                        type="button"
                        wire:key="chart-tab-{{ $tabKey }}"
                        wire:click="setActiveTab('{{ $tabKey }}')"
                        role="tab"
                        aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                        class="shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium transition {{ $activeTab === $tabKey
                            ? 'border-accent text-accent'
                            : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-800 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200' }}"
                    >
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- B) Consent --}}
        @if ($activeTab === 'consent')
        <flux:card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="2">{{ __('Consent') }}</flux:heading>
                @if ($this->procedure->consent_completed_at)
                    <flux:badge color="green">{{ __('Completed :date', ['date' => $this->procedure->consent_completed_at->format('d M, g:i A')]) }}</flux:badge>
                @endif
            </div>

            @php($consentAttachments = $this->procedure->attachments->where('type', \App\Enums\ProcedureAttachmentType::Consent))

            <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4 space-y-4">
                <form wire:submit="saveConsentPhotos" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <flux:label>{{ __('Upload consent photo(s)') }}</flux:label>
                        <input
                            type="file"
                            wire:model="consentPhotos"
                            multiple
                            accept="image/jpeg,image/png,image/webp"
                            class="mt-1 block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:text-zinc-300 dark:file:bg-white dark:file:text-zinc-900"
                        />
                        <div wire:loading wire:target="consentPhotos" class="mt-1 text-xs text-zinc-500">{{ __('Uploading...') }}</div>
                        <flux:text class="mt-1 text-xs text-zinc-500">
                            {{ __(':count of 2 uploaded', ['count' => $consentAttachments->count()]) }}
                        </flux:text>
                        <flux:error name="consentPhotos" />
                        <flux:error name="consentPhotos.*" />
                    </div>
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Upload') }}</flux:button>
                </form>

                @unless ($this->procedure->consent_completed_at)
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="check-circle"
                        wire:click="markConsentComplete"
                        :disabled="$consentAttachments->count() < 2"
                    >
                        {{ __('Mark consent complete') }}
                    </flux:button>
                    @if ($consentAttachments->count() < 2)
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Upload at least 2 consent photos before marking consent complete.') }}
                        </flux:text>
                    @endif
                @endunless
            </fieldset>

            @if ($consentAttachments->isNotEmpty())
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($consentAttachments as $attachment)
                        <div wire:key="consent-attachment-{{ $attachment->id }}" class="group relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <a href="{{ route('indoor.attachments.show', $attachment) }}" target="_blank">
                                <img src="{{ route('indoor.attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" class="h-24 w-full object-cover" />
                            </a>
                            @unless ($this->isReadOnly)
                                <button
                                    type="button"
                                    wire:click="deleteAttachment({{ $attachment->id }})"
                                    wire:confirm="{{ __('Delete this attachment?') }}"
                                    class="absolute top-1 right-1 rounded-full bg-black/60 p-1 text-white opacity-0 transition group-hover:opacity-100"
                                >
                                    <flux:icon name="trash" class="size-3" />
                                </button>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
        @endif

        {{-- C) Pre-op orders --}}
        @if ($activeTab === 'pre-op')
        <flux:card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="2">{{ __('Pre-op Orders') }}</flux:heading>
                @if ($this->procedure->pre_op_completed_at)
                    <flux:badge color="green">{{ __('Completed :date', ['date' => $this->procedure->pre_op_completed_at->format('d M, g:i A')]) }} — {{ $this->procedure->pre_op_done_by }}</flux:badge>
                @endif
            </div>

            <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                <form wire:submit="savePreOpOrder" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <flux:checkbox wire:model="preOpGiveBath" label="{{ __('Give bath') }}" />
                        <flux:checkbox wire:model="preOpProvideHospitalDress" label="{{ __('Provide hospital dress') }}" />
                        <flux:checkbox wire:model="preOpShaveAndPrepare" label="{{ __('Shave & prepare') }}" />
                        <flux:checkbox wire:model.live="preOpMarkOperationSite" label="{{ __('Mark operation site') }}" />

                        @if ($preOpMarkOperationSite)
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Operation site') }}</flux:label>
                                <flux:input wire:model="preOpOperationSite" />
                                <flux:error name="preOpOperationSite" />
                            </flux:field>
                        @endif

                        <flux:field>
                            <flux:label>{{ __('NPO from') }}</flux:label>
                            <flux:input type="datetime-local" wire:model="preOpNpoFrom" />
                            <flux:error name="preOpNpoFrom" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Send to OT at') }}</flux:label>
                            <flux:input type="datetime-local" wire:model="preOpSendToOtAt" />
                            <flux:error name="preOpSendToOtAt" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Blood pints') }}</flux:label>
                            <flux:input type="number" min="0" max="20" wire:model="preOpBloodPints" />
                            <flux:error name="preOpBloodPints" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Investigations') }}</flux:label>
                        <flux:textarea wire:model="preOpInvestigations" rows="2" />
                        <flux:error name="preOpInvestigations" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Pre-medication') }}</flux:label>
                        <flux:textarea wire:model="preOpPreMedication" rows="2" />
                        <flux:error name="preOpPreMedication" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Other orders') }}</flux:label>
                        <flux:textarea wire:model="preOpOtherOrders" rows="2" />
                        <flux:error name="preOpOtherOrders" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Upload pre-op photo(s)') }}</flux:label>
                        <input
                            type="file"
                            wire:model="preOpPhotos"
                            multiple
                            accept="image/jpeg,image/png,image/webp"
                            class="mt-1 block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:text-zinc-300 dark:file:bg-white dark:file:text-zinc-900"
                        />
                        <flux:error name="preOpPhotos" />
                        <flux:error name="preOpPhotos.*" />
                    </flux:field>

                    <div class="flex flex-wrap items-end justify-between gap-4 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <flux:button type="submit" variant="primary" icon="check">{{ __('Save pre-op order') }}</flux:button>

                        <div class="flex flex-1 items-end gap-2 sm:max-w-sm">
                            <flux:field class="flex-1">
                                <flux:label>{{ __('Done by') }}</flux:label>
                                <flux:input wire:model="preOpDoneBy" placeholder="{{ __('Nurse / staff name') }}" />
                                <flux:error name="preOpDoneBy" />
                            </flux:field>
                            <flux:button type="button" variant="filled" icon="check-circle" wire:click="completePreOpOrder">
                                {{ __('Mark complete') }}
                            </flux:button>
                        </div>
                    </div>
                </form>
            </fieldset>

            @php($preOpAttachments = $this->procedure->attachments->where('type', \App\Enums\ProcedureAttachmentType::PreOp))
            @if ($preOpAttachments->isNotEmpty())
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($preOpAttachments as $attachment)
                        <div wire:key="preop-attachment-{{ $attachment->id }}" class="group relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <a href="{{ route('indoor.attachments.show', $attachment) }}" target="_blank">
                                <img src="{{ route('indoor.attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" class="h-24 w-full object-cover" />
                            </a>
                            @unless ($this->isReadOnly)
                                <button
                                    type="button"
                                    wire:click="deleteAttachment({{ $attachment->id }})"
                                    wire:confirm="{{ __('Delete this attachment?') }}"
                                    class="absolute top-1 right-1 rounded-full bg-black/60 p-1 text-white opacity-0 transition group-hover:opacity-100"
                                >
                                    <flux:icon name="trash" class="size-3" />
                                </button>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
        @endif

        {{-- D) Vitals --}}
        @if ($activeTab === 'vitals')
        <flux:card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="2">{{ __('Vitals') }}</flux:heading>
                @if ($this->procedure->isVitalsOverdue())
                    <flux:badge color="red">{{ __('Overdue for this hour') }}</flux:badge>
                @endif
            </div>

            <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                <form wire:submit="saveVital" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <flux:field class="col-span-2">
                            <flux:label>{{ __('Recorded at') }}</flux:label>
                            <flux:input type="datetime-local" wire:model="vitalRecordedAt" required />
                            <flux:error name="vitalRecordedAt" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Pulse') }}</flux:label>
                            <flux:input type="number" wire:model="vitalPulse" />
                            <flux:error name="vitalPulse" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Resp. rate') }}</flux:label>
                            <flux:input type="number" wire:model="vitalRespRate" />
                            <flux:error name="vitalRespRate" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('BP systolic') }}</flux:label>
                            <flux:input type="number" wire:model="vitalBpSystolic" />
                            <flux:error name="vitalBpSystolic" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('BP diastolic') }}</flux:label>
                            <flux:input type="number" wire:model="vitalBpDiastolic" />
                            <flux:error name="vitalBpDiastolic" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Temp (°F)') }}</flux:label>
                            <flux:input type="number" step="0.1" wire:model="vitalTemp" />
                            <flux:error name="vitalTemp" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('CVP') }}</flux:label>
                            <flux:input wire:model="vitalCvp" />
                            <flux:error name="vitalCvp" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('IV fluid') }}</flux:label>
                            <flux:input wire:model="vitalIvFluid" />
                            <flux:error name="vitalIvFluid" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Oral / NG') }}</flux:label>
                            <flux:input wire:model="vitalOralNg" />
                            <flux:error name="vitalOralNg" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Urine') }}</flux:label>
                            <flux:input wire:model="vitalUrine" />
                            <flux:error name="vitalUrine" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Stool') }}</flux:label>
                            <flux:input wire:model="vitalStool" />
                            <flux:error name="vitalStool" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Aspirate') }}</flux:label>
                            <flux:input wire:model="vitalAspirate" />
                            <flux:error name="vitalAspirate" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Drain') }}</flux:label>
                            <flux:input wire:model="vitalDrain" />
                            <flux:error name="vitalDrain" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Notes') }}</flux:label>
                        <flux:textarea wire:model="vitalNotes" rows="2" />
                        <flux:error name="vitalNotes" />
                    </flux:field>

                    <flux:button type="submit" variant="primary" icon="plus">{{ __('Record vitals') }}</flux:button>
                </form>
            </fieldset>

            <div class="mt-6 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Time') }}</flux:table.column>
                        <flux:table.column>{{ __('Pulse') }}</flux:table.column>
                        <flux:table.column>{{ __('BP') }}</flux:table.column>
                        <flux:table.column>{{ __('Resp') }}</flux:table.column>
                        <flux:table.column>{{ __('Temp') }}</flux:table.column>
                        <flux:table.column>{{ __('I/O') }}</flux:table.column>
                        <flux:table.column>{{ __('By') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->procedure->vitals as $vital)
                            <flux:table.row wire:key="vital-{{ $vital->id }}">
                                <flux:table.cell>{{ $vital->recorded_at->format('d M, g:i A') }}</flux:table.cell>
                                <flux:table.cell>{{ $vital->pulse ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $vital->bp_systolic ?? '-' }}/{{ $vital->bp_diastolic ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $vital->resp_rate ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $vital->temp ?? '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ __('In') }}: {{ $vital->iv_fluid ?? $vital->oral_ng ?? '-' }} / {{ __('Out') }}: {{ $vital->urine ?? '-' }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $vital->recorder?->name ?? '-' }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="7" class="text-center text-zinc-500">{{ __('No vitals recorded yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </flux:card>
        @endif

        {{-- E) Fetal heart rate --}}
        @if ($activeTab === 'fhr' && $this->requiresFetalHeart)
            <flux:card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading level="2">{{ __('Fetal Heart Rate') }}</flux:heading>
                    @if ($this->procedure->isFetalHeartOverdue())
                        <flux:badge color="red">{{ __('Overdue for this hour') }}</flux:badge>
                    @endif
                </div>

                <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                    <form wire:submit="saveFetalHeart" class="grid grid-cols-1 gap-4 sm:grid-cols-4 sm:items-end">
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Recorded at') }}</flux:label>
                            <flux:input type="datetime-local" wire:model="fhrRecordedAt" required />
                            <flux:error name="fhrRecordedAt" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('FHR (bpm)') }}</flux:label>
                            <flux:input type="number" wire:model="fhrValue" required />
                            <flux:error name="fhrValue" />
                        </flux:field>
                        <flux:field class="sm:col-span-4">
                            <flux:label>{{ __('Notes') }}</flux:label>
                            <flux:input wire:model="fhrNotes" />
                            <flux:error name="fhrNotes" />
                        </flux:field>
                        <div>
                            <flux:button type="submit" variant="primary" icon="plus">{{ __('Record FHR') }}</flux:button>
                        </div>
                    </form>
                </fieldset>

                <div class="mt-6 overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Time') }}</flux:table.column>
                            <flux:table.column>{{ __('FHR (bpm)') }}</flux:table.column>
                            <flux:table.column>{{ __('Notes') }}</flux:table.column>
                            <flux:table.column>{{ __('By') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->procedure->fetalHearts as $reading)
                                <flux:table.row wire:key="fhr-{{ $reading->id }}">
                                    <flux:table.cell>{{ $reading->recorded_at->format('d M, g:i A') }}</flux:table.cell>
                                    <flux:table.cell>{{ $reading->fhr }}</flux:table.cell>
                                    <flux:table.cell>{{ $reading->notes ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $reading->recorder?->name ?? '-' }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('No readings recorded yet.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>
        @endif

        {{-- F) Medications --}}
        @if ($activeTab === 'medications')
        <flux:card>
            <flux:heading level="2">{{ __('Medications') }}</flux:heading>

            <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                <form wire:submit="prescribeMedication" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Form') }}</flux:label>
                            <flux:select wire:model.live="medForm">
                                <option value="tab">{{ __('Tablet') }}</option>
                                <option value="inj">{{ __('Injection') }}</option>
                                <option value="drip">{{ __('Drip') }}</option>
                            </flux:select>
                            <flux:error name="medForm" />
                        </flux:field>

                        @if ($medForm === 'tab')
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Medicine (catalog)') }}</flux:label>
                                <x-searchable-select
                                    wire:model="medMedicineId"
                                    :options="$this->medicines->map(fn ($m) => ['value' => $m->id, 'label' => filled($m->unit) ? $m->name.' ('.$m->unit.')' : $m->name, 'keywords' => $m->short_form])->values()->all()"
                                    :placeholder="__('Search medicine')"
                                />
                                <flux:error name="medMedicineId" />
                            </flux:field>
                        @elseif ($medForm === 'inj')
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Injection (catalog)') }}</flux:label>
                                <x-searchable-select
                                    wire:model="medInjectionId"
                                    :options="$this->injections->map(fn ($i) => ['value' => $i->id, 'label' => $i->name, 'keywords' => $i->short_form])->values()->all()"
                                    :placeholder="__('Search injection')"
                                />
                                <flux:error name="medInjectionId" />
                            </flux:field>
                        @else
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Drip base (catalog)') }}</flux:label>
                                <x-searchable-select
                                    wire:model="medDripBaseId"
                                    :options="$this->dripBases->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->values()->all()"
                                    :placeholder="__('Search drip base')"
                                />
                                <flux:error name="medDripBaseId" />
                            </flux:field>
                        @endif
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Custom name') }}</flux:label>
                        <flux:input wire:model="medCustomName" placeholder="{{ __('Use if not in the catalog above') }}" />
                        <flux:error name="medCustomName" />
                    </flux:field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Dose') }}</flux:label>
                            <flux:input wire:model="medDose" placeholder="{{ __('e.g. 500mg, 1 amp') }}" required />
                            <flux:error name="medDose" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Route') }}</flux:label>
                            <flux:input wire:model="medRoute" placeholder="{{ __('e.g. PO, IV, IM') }}" />
                            <flux:error name="medRoute" />
                        </flux:field>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Schedule') }}</flux:label>
                            <flux:select wire:model.live="medScheduleType">
                                <option value="once_now">{{ __('Once now') }}</option>
                                <option value="once_at">{{ __('Once at time') }}</option>
                                <option value="every_hour">{{ __('Every hour') }}</option>
                                <option value="now_and_at">{{ __('Now and at time(s)') }}</option>
                                <option value="at_times">{{ __('At scheduled time(s)') }}</option>
                            </flux:select>
                            <flux:error name="medScheduleType" />
                        </flux:field>

                        @if (in_array($medScheduleType, ['once_at', 'now_and_at', 'at_times'], true))
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Time(s), HH:MM comma-separated') }}</flux:label>
                                <flux:input wire:model="medScheduleTimesText" placeholder="{{ __('e.g. 08:00, 14:00, 20:00') }}" />
                                <flux:error name="medScheduleTimesText" />
                            </flux:field>
                        @endif

                        @if ($medScheduleType === 'every_hour')
                            <flux:field>
                                <flux:label>{{ __('Interval (hours)') }}</flux:label>
                                <flux:input type="number" min="1" max="24" wire:model="medIntervalHours" />
                                <flux:error name="medIntervalHours" />
                            </flux:field>
                        @endif
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Notes') }}</flux:label>
                        <flux:textarea wire:model="medNotes" rows="2" />
                        <flux:error name="medNotes" />
                    </flux:field>

                    <flux:button type="submit" variant="primary" icon="plus">{{ __('Prescribe medication') }}</flux:button>
                </form>
            </fieldset>

            <div class="mt-6 space-y-4">
                @forelse ($this->procedure->medications as $medication)
                    <div wire:key="medication-{{ $medication->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <flux:heading level="3" class="text-base">{{ $medication->displayName() }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500">
                                    {{ $medication->form->label() }} &middot; {{ $medication->dose }}
                                    @if ($medication->route)
                                        &middot; {{ $medication->route }}
                                    @endif
                                    &middot; {{ $medication->schedule_type->label() }}
                                    &middot; {{ __('by') }} {{ $medication->prescriber?->name ?? '-' }}
                                </flux:text>
                            </div>
                        </div>

                        @if ($medication->doses->isNotEmpty())
                            <div class="mt-3 overflow-x-auto">
                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column>{{ __('Due') }}</flux:table.column>
                                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                                        <flux:table.column>{{ __('Given at') }}</flux:table.column>
                                        <flux:table.column>{{ __('By') }}</flux:table.column>
                                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                                    </flux:table.columns>
                                    <flux:table.rows>
                                        @foreach ($medication->doses as $dose)
                                            <flux:table.row wire:key="dose-{{ $dose->id }}">
                                                <flux:table.cell>{{ $dose->due_at->format('d M, g:i A') }}</flux:table.cell>
                                                <flux:table.cell>
                                                    <flux:badge size="sm" color="{{ $dose->status->value === 'given' ? 'green' : ($dose->status->value === 'skipped' ? 'zinc' : ($dose->due_at->isPast() ? 'red' : 'amber')) }}">
                                                        {{ $dose->status->label() }}
                                                    </flux:badge>
                                                </flux:table.cell>
                                                <flux:table.cell>{{ $dose->given_at?->format('d M, g:i A') ?? '-' }}</flux:table.cell>
                                                <flux:table.cell>{{ $dose->givenBy?->name ?? '-' }}</flux:table.cell>
                                                <flux:table.cell class="text-right">
                                                    @if ($dose->status->value === 'pending' && ! $this->isReadOnly)
                                                        <flux:button size="sm" variant="primary" wire:click="markDoseGiven({{ $dose->id }})">
                                                            {{ __('Given') }}
                                                        </flux:button>
                                                        <flux:button size="sm" variant="ghost" wire:click="markDoseSkipped({{ $dose->id }})">
                                                            {{ __('Skip') }}
                                                        </flux:button>
                                                    @endif
                                                </flux:table.cell>
                                            </flux:table.row>
                                        @endforeach
                                    </flux:table.rows>
                                </flux:table>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500">{{ __('No medications prescribed yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </flux:card>
        @endif

        {{-- G) Operation / Delivery note --}}
        @if ($activeTab === 'note')
        <flux:card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="2">
                    {{ $this->noteStyle === \App\Enums\ProcedureNoteStyle::Delivery ? __('Delivery Note') : __('Operation Note') }}
                </flux:heading>

                <div class="flex flex-wrap gap-2">
                    @unless ($this->isReadOnly)
                        @if (! $this->procedure->operation_started_at)
                            <flux:button size="sm" variant="primary" icon="play" wire:click="markOperationStarted">
                                {{ __('Mark started') }}
                            </flux:button>
                        @elseif (! $this->procedure->operation_completed_at)
                            <flux:button size="sm" variant="primary" icon="check" wire:click="markOperationCompleted">
                                {{ __('Mark completed') }}
                            </flux:button>
                        @endif
                    @endunless
                    @if ($this->procedure->operation_started_at)
                        <flux:badge color="amber">{{ __('Started :date', ['date' => $this->procedure->operation_started_at->format('d M, g:i A')]) }}</flux:badge>
                    @endif
                    @if ($this->procedure->operation_completed_at)
                        <flux:badge color="green">{{ __('Completed :date', ['date' => $this->procedure->operation_completed_at->format('d M, g:i A')]) }}</flux:badge>
                    @endif
                </div>
            </div>

            @if ($this->noteStyle === \App\Enums\ProcedureNoteStyle::Delivery)
                <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                    <form wire:submit="saveDeliveryNote" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>{{ __('Labour type') }}</flux:label>
                                <flux:input wire:model="dnLabourType" />
                                <flux:error name="dnLabourType" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Procedure name') }}</flux:label>
                                <flux:input wire:model="dnProcedureName" />
                                <flux:error name="dnProcedureName" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Delivered at') }}</flux:label>
                                <flux:input type="datetime-local" wire:model="dnDeliveredAt" />
                                <flux:error name="dnDeliveredAt" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Obstetrician') }}</flux:label>
                                <flux:input wire:model="dnObstetrician" />
                                <flux:error name="dnObstetrician" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Assistant') }}</flux:label>
                                <flux:input wire:model="dnAssistant" />
                                <flux:error name="dnAssistant" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Analgesia') }}</flux:label>
                                <flux:input wire:model="dnAnalgesia" />
                                <flux:error name="dnAnalgesia" />
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>{{ __('Labour — 1st stage') }}</flux:label>
                                <flux:input wire:model="dnLabourFirstStage" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Labour — 2nd stage') }}</flux:label>
                                <flux:input wire:model="dnLabourSecondStage" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Labour — 3rd stage') }}</flux:label>
                                <flux:input wire:model="dnLabourThirdStage" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>{{ __('Delivery details') }}</flux:label>
                            <flux:textarea wire:model="dnDeliveryDetails" rows="3" />
                            <flux:error name="dnDeliveryDetails" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Complications') }}</flux:label>
                            <flux:textarea wire:model="dnComplications" rows="2" />
                            <flux:error name="dnComplications" />
                        </flux:field>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                            <flux:field>
                                <flux:label>{{ __('Baby sex') }}</flux:label>
                                <flux:select wire:model="dnBabySex">
                                    <option value="">{{ __('Select') }}</option>
                                    <option value="male">{{ __('Male') }}</option>
                                    <option value="female">{{ __('Female') }}</option>
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Baby weight') }}</flux:label>
                                <flux:input wire:model="dnBabyWeight" placeholder="{{ __('e.g. 3.2 kg') }}" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('APGAR score') }}</flux:label>
                                <flux:input wire:model="dnApgarScore" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Resuscitated by') }}</flux:label>
                                <flux:input wire:model="dnResuscitatedBy" />
                            </flux:field>
                        </div>

                        <flux:button type="submit" variant="primary" icon="check">{{ __('Save delivery note') }}</flux:button>
                    </form>
                </fieldset>
            @else
                <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                    <form wire:submit="saveOperationNote" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>{{ __('Operated on') }}</flux:label>
                                <flux:input type="date" wire:model="opOperatedOn" />
                                <flux:error name="opOperatedOn" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Started at') }}</flux:label>
                                <flux:input type="time" wire:model="opStartedAt" />
                                <flux:error name="opStartedAt" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Ended at') }}</flux:label>
                                <flux:input type="time" wire:model="opEndedAt" />
                                <flux:error name="opEndedAt" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Operation') }}</flux:label>
                                <flux:input wire:model="opOperation" />
                                <flux:error name="opOperation" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Surgeon') }}</flux:label>
                                <flux:input wire:model="opSurgeon" />
                                <flux:error name="opSurgeon" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Nurse') }}</flux:label>
                                <flux:input wire:model="opNurse" />
                                <flux:error name="opNurse" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Anaesthesia') }}</flux:label>
                                <flux:input wire:model="opAnaesthesia" />
                                <flux:error name="opAnaesthesia" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>{{ __('Findings') }}</flux:label>
                            <flux:textarea wire:model="opFindings" rows="3" />
                            <flux:error name="opFindings" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Procedure') }}</flux:label>
                            <flux:textarea wire:model="opProcedureText" rows="3" />
                            <flux:error name="opProcedureText" />
                        </flux:field>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>{{ __('Closure') }}</flux:label>
                                <flux:textarea wire:model="opClosure" rows="2" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Drain') }}</flux:label>
                                <flux:textarea wire:model="opDrain" rows="2" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Biopsy') }}</flux:label>
                                <flux:textarea wire:model="opBiopsy" rows="2" />
                            </flux:field>
                        </div>

                        <flux:button type="submit" variant="primary" icon="check">{{ __('Save operation note') }}</flux:button>
                    </form>
                </fieldset>
            @endif
        </flux:card>
        @endif

        {{-- H) Post-op --}}
        @if ($activeTab === 'post-op')
        <flux:card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="2">{{ __('Post-op Orders') }}</flux:heading>
                @if ($this->procedure->post_op_completed_at)
                    <flux:badge color="green">{{ __('Completed :date', ['date' => $this->procedure->post_op_completed_at->format('d M, g:i A')]) }}</flux:badge>
                @endif
            </div>

            <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                <form wire:submit="savePostOpOrder" class="space-y-4">
                    <flux:checkbox wire:model="postOpMaintainIntakeOutput" label="{{ __('Maintain intake / output chart') }}" />

                    <flux:field>
                        <flux:label>{{ __('NPO till') }}</flux:label>
                        <flux:input type="datetime-local" wire:model="postOpNpoTill" />
                        <flux:error name="postOpNpoTill" />
                    </flux:field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Antibiotics') }}</flux:label>
                            <flux:textarea wire:model="postOpAntibiotics" rows="2" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('IV fluids') }}</flux:label>
                            <flux:textarea wire:model="postOpIvFluids" rows="2" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Analgesics') }}</flux:label>
                            <flux:textarea wire:model="postOpAnalgesics" rows="2" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Antiemetics') }}</flux:label>
                            <flux:textarea wire:model="postOpAntiemetics" rows="2" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Biopsy') }}</flux:label>
                            <flux:textarea wire:model="postOpBiopsy" rows="2" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Other orders') }}</flux:label>
                            <flux:textarea wire:model="postOpOthers" rows="2" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Upload post-op photo(s)') }}</flux:label>
                        <input
                            type="file"
                            wire:model="postOpPhotos"
                            multiple
                            accept="image/jpeg,image/png,image/webp"
                            class="mt-1 block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:text-zinc-300 dark:file:bg-white dark:file:text-zinc-900"
                        />
                        <flux:error name="postOpPhotos" />
                        <flux:error name="postOpPhotos.*" />
                    </flux:field>

                    <div class="flex flex-wrap items-end justify-between gap-4 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <flux:button type="submit" variant="primary" icon="check">{{ __('Save post-op order') }}</flux:button>

                        <div class="flex flex-1 items-end gap-2 sm:max-w-sm">
                            <flux:field class="flex-1">
                                <flux:label>{{ __('Done by') }}</flux:label>
                                <flux:input wire:model="postOpDoneBy" placeholder="{{ __('Nurse / staff name') }}" />
                                <flux:error name="postOpDoneBy" />
                            </flux:field>
                            <flux:button type="button" variant="filled" icon="check-circle" wire:click="completePostOpOrder">
                                {{ __('Mark complete') }}
                            </flux:button>
                        </div>
                    </div>
                </form>
            </fieldset>

            @php($postOpAttachments = $this->procedure->attachments->where('type', \App\Enums\ProcedureAttachmentType::PostOp))
            @if ($postOpAttachments->isNotEmpty())
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($postOpAttachments as $attachment)
                        <div wire:key="postop-attachment-{{ $attachment->id }}" class="group relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <a href="{{ route('indoor.attachments.show', $attachment) }}" target="_blank">
                                <img src="{{ route('indoor.attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" class="h-24 w-full object-cover" />
                            </a>
                            @unless ($this->isReadOnly)
                                <button
                                    type="button"
                                    wire:click="deleteAttachment({{ $attachment->id }})"
                                    wire:confirm="{{ __('Delete this attachment?') }}"
                                    class="absolute top-1 right-1 rounded-full bg-black/60 p-1 text-white opacity-0 transition group-hover:opacity-100"
                                >
                                    <flux:icon name="trash" class="size-3" />
                                </button>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
        @endif

        {{-- I) Progress notes --}}
        @if ($activeTab === 'progress')
        <flux:card>
            <flux:heading level="2">{{ __('Progress Notes') }}</flux:heading>

            <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                <form wire:submit="addProgressNote" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:field class="sm:col-span-1">
                            <flux:label>{{ __('Date & time') }}</flux:label>
                            <flux:input type="datetime-local" wire:model="progressNotedAt" required />
                            <flux:error name="progressNotedAt" />
                        </flux:field>
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Note') }}</flux:label>
                            <flux:textarea wire:model="progressNote" rows="2" required />
                            <flux:error name="progressNote" />
                        </flux:field>
                    </div>
                    <flux:button type="submit" variant="primary" icon="plus">{{ __('Add note') }}</flux:button>
                </form>
            </fieldset>

            <div class="mt-6 space-y-3">
                @forelse ($this->procedure->progressNotes as $note)
                    <div wire:key="progress-note-{{ $note->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                            <span>{{ $note->noted_at->format('d M Y, g:i A') }}</span>
                            <span>{{ $note->doctorUser?->name ?? '-' }}</span>
                        </div>
                        <p class="mt-1 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $note->note }}</p>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500">{{ __('No progress notes yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </flux:card>
        @endif

        {{-- J) Discharge --}}
        @if ($activeTab === 'discharge')
        <flux:card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="2">{{ __('Discharge') }}</flux:heading>
                @if ($this->procedure->isDischarged())
                    <flux:badge color="green">{{ __('Discharged :date', ['date' => $this->procedure->discharged_at->format('d M, g:i A')]) }} — {{ $this->procedure->discharger?->name }}</flux:badge>
                @endif
            </div>

            <fieldset @if ($this->isReadOnly) disabled @endif class="mt-4">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Blood group') }}</flux:label>
                            <flux:input wire:model="dischargeBloodGroup" />
                            <flux:error name="dischargeBloodGroup" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Parity') }}</flux:label>
                            <flux:input wire:model="dischargeParity" />
                            <flux:error name="dischargeParity" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Procedure time') }}</flux:label>
                            <flux:input type="datetime-local" wire:model="dischargeProcedureTime" />
                            <flux:error name="dischargeProcedureTime" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Indication') }}</flux:label>
                        <flux:input wire:model="dischargeIndication" />
                        <flux:error name="dischargeIndication" />
                    </flux:field>

                    @if ($this->requiresBirthCertificate)
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>{{ __('Baby sex') }}</flux:label>
                                <flux:select wire:model="dischargeBabySex">
                                    <option value="">{{ __('Select') }}</option>
                                    <option value="male">{{ __('Male') }}</option>
                                    <option value="female">{{ __('Female') }}</option>
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Baby weight') }}</flux:label>
                                <flux:input wire:model="dischargeBabyWeight" placeholder="{{ __('e.g. 3.2 kg') }}" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Baby condition') }}</flux:label>
                                <flux:input wire:model="dischargeBabyCondition" />
                            </flux:field>
                        </div>
                    @endif

                    <flux:field>
                        <flux:label>{{ __('Rx (advice on discharge)') }}</flux:label>
                        <flux:textarea wire:model="dischargeRxText" rows="3" />
                        <flux:error name="dischargeRxText" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Outcome summary') }}</flux:label>
                        <flux:textarea wire:model="dischargeOutcomeSummary" rows="3" />
                        <flux:error name="dischargeOutcomeSummary" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Stitch removal date') }}</flux:label>
                        <flux:input type="date" wire:model="dischargeStitchRemovalDate" />
                        <flux:error name="dischargeStitchRemovalDate" />
                    </flux:field>

                    <div class="flex flex-wrap justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <flux:button type="button" variant="ghost" icon="check" wire:click="saveDischargeDetail">
                            {{ __('Save details') }}
                        </flux:button>
                        @unless ($this->procedure->isDischarged())
                            <flux:button
                                type="button"
                                variant="primary"
                                icon="arrow-right-start-on-rectangle"
                                wire:click="dischargePatient"
                                wire:confirm="{{ __('Discharge this patient? This will close the admission.') }}"
                            >
                                {{ __('Discharge patient') }}
                            </flux:button>
                        @endunless
                    </div>
                </div>
            </fieldset>
        </flux:card>
        @endif

        <div class="flex items-center justify-between gap-3">
            <flux:button
                type="button"
                variant="ghost"
                icon="arrow-left"
                wire:click="previousTab"
                :disabled="$this->isFirstTab"
            >
                {{ __('Previous') }}
            </flux:button>

            <flux:text class="text-sm text-zinc-500">
                {{ __(':current of :total', ['current' => $this->activeTabIndex + 1, 'total' => count($this->chartTabs)]) }}
            </flux:text>

            <flux:button
                type="button"
                variant="primary"
                icon-trailing="arrow-right"
                wire:click="nextTab"
                :disabled="$this->isLastTab"
            >
                {{ __('Next') }}
            </flux:button>
        </div>
    </div>
</div>
