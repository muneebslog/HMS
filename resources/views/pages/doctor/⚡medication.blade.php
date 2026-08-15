<?php

use App\Actions\ResolveDripShareDoctor;
use App\Enums\DripChargeStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Models\DoctorRecheck;
use App\Models\DripBase;
use App\Models\DripCharge;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\Shift;
use App\Services\TokenDisplayService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Medication')] class extends Component
{
    public ?int $selectedTokenId = null;

    public bool $showHistoryModal = false;

    public bool $showOrderPreviewModal = false;

    /**
     * @var array{
     *     medicines: list<array{name: string, dose: string, comment: string|null}>,
     *     injections: list<array{name: string, administration_type: string, comment: string|null}>,
     *     drips: list<array{name: string, additives: list<array{name: string}>}>,
     *     notes: string|null
     * }
     */
    public array $orderPreview = [
        'medicines' => [],
        'injections' => [],
        'drips' => [],
        'notes' => null,
    ];

    public string $activeOrderTab = 'medicines';

    public string $notes = '';

    public string $complaintOrDiagnosis = '';

    public string $suggestedPrice = '';

    public ?int $dripServiceId = null;

    public string $recheckMinutes = '15';

    public string $recheckNote = '';

    public bool $showRecheckForm = false;

    /**
     * @var list<array{
     *     selection: string|null,
     *     dose: string,
     *     administration_type: string,
     *     comment: string
     * }>
     */
    public array $medicationLines = [];

    /**
     * @var list<array{drip_base_id: int|null, additives: list<array{injection_id: int|string|null}>}>
     */
    public array $dripLines = [];

    /**
     * Waiting/serving tokens that need medication for the current open shift.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function queue(): Collection
    {
        $shift = Shift::current();

        if ($shift === null) {
            return new Collection;
        }

        return QueueToken::query()
            ->with(['patient', 'serviceQueue.service', 'serviceQueue.doctor', 'vital', 'vitals.recordedBy', 'medicationOrder', 'activeRecheck'])
            ->whereIn('status', ['waiting', 'serving'])
            ->where(function ($query): void {
                $query->whereDoesntHave('medicationOrder')
                    ->orWhereHas(
                        'doctorRechecks',
                        fn ($recheckQuery) => $recheckQuery->whereNull('acknowledged_at')
                    );
            })
            ->whereHas('serviceQueue', function ($query) use ($shift): void {
                $query->where('status', 'open')
                    ->forShift($shift)
                    ->whereHas('service', fn ($serviceQuery) => $serviceQuery->where('needs_medication', true));
            })
            ->orderByRaw('arrived_at is null')
            ->orderBy('arrived_at')
            ->orderBy('token_number')
            ->get()
            ->sortByDesc(fn (QueueToken $token): int => $token->activeRecheck?->isDue() ? 1 : 0)
            ->values();
    }

    /**
     * The token currently being ordered for.
     */
    #[Computed]
    public function selectedToken(): ?QueueToken
    {
        if ($this->selectedTokenId === null) {
            return null;
        }

        return $this->queue->firstWhere('id', $this->selectedTokenId)
            ?? QueueToken::with(['patient', 'serviceQueue.service', 'serviceQueue.doctor', 'vital', 'vitals.recordedBy', 'medicationOrder.medicines', 'medicationOrder.injections', 'medicationOrder.drips.additives', 'activeRecheck'])
                ->find($this->selectedTokenId);
    }

    /**
     * Whether the selected patient's queue uses the single-token TV layout.
     */
    #[Computed]
    public function usesSingleTokenLayout(): bool
    {
        $queue = $this->selectedToken?->serviceQueue;

        return $queue !== null
            && app(TokenDisplayService::class)->isSingleTokenQueue($queue);
    }

    /**
     * Whether saving the order should advance the displayed token.
     */
    #[Computed]
    public function advancesDisplayToken(): bool
    {
        $queue = $this->selectedToken?->serviceQueue;

        return $queue !== null
            && app(TokenDisplayService::class)->followsDoctorToken($queue);
    }

    /**
     * Active drip billable services.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function dripServices(): Collection
    {
        return Service::query()
            ->active()
            ->where('is_drip', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Active medicine catalog.
     *
     * @return Collection<int, Medicine>
     */
    #[Computed]
    public function medicines(): Collection
    {
        return Medicine::query()->active()->orderBy('name')->get();
    }

    /**
     * Active injection catalog.
     *
     * @return Collection<int, Injection>
     */
    #[Computed]
    public function injections(): Collection
    {
        return Injection::query()->active()->orderBy('name')->get();
    }

    /**
     * Active drip base catalog.
     *
     * @return Collection<int, DripBase>
     */
    #[Computed]
    public function dripBases(): Collection
    {
        return DripBase::query()->active()->orderBy('name')->get();
    }

    /**
     * Medicine options for searchable select.
     *
     * @return list<array{value: int, label: string, keywords: string}>
     */
    #[Computed]
    public function medicineOptions(): array
    {
        return $this->medicines
            ->map(function (Medicine $medicine): array {
                $label = filled($medicine->unit)
                    ? $medicine->name.' ('.$medicine->unit.')'
                    : $medicine->name;

                if (filled($medicine->short_form)) {
                    $label = $medicine->short_form.' — '.$label;
                }

                return [
                    'value' => $medicine->id,
                    'label' => $label,
                    'keywords' => trim($medicine->name.' '.($medicine->unit ?? '').' '.($medicine->short_form ?? '')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Injection options for searchable select.
     *
     * @return list<array{value: int, label: string, keywords: string}>
     */
    #[Computed]
    public function injectionOptions(): array
    {
        return $this->injections
            ->map(function (Injection $injection): array {
                $label = $injection->name;

                if (filled($injection->short_form)) {
                    $label = $injection->short_form.' — '.$label;
                }

                return [
                    'value' => $injection->id,
                    'label' => $label,
                    'keywords' => trim($injection->name.' '.($injection->short_form ?? '')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Active medicines and injections for the unified order selector.
     *
     * @return list<array{value: string, label: string, keywords: string}>
     */
    #[Computed]
    public function medicationOptions(): array
    {
        $medicines = collect($this->medicineOptions)
            ->map(fn (array $option): array => [
                ...$option,
                'value' => 'medicine:'.$option['value'],
                'label' => __('Medicine').' — '.$option['label'],
            ]);

        $injections = collect($this->injectionOptions)
            ->map(fn (array $option): array => [
                ...$option,
                'value' => 'injection:'.$option['value'],
                'label' => __('Injection').' — '.$option['label'],
            ]);

        return $medicines
            ->merge($injections)
            ->values()
            ->all();
    }

    /**
     * Drip base options for searchable select.
     *
     * @return list<array{value: int, label: string}>
     */
    #[Computed]
    public function dripBaseOptions(): array
    {
        return $this->dripBases
            ->map(fn (DripBase $dripBase): array => [
                'value' => $dripBase->id,
                'label' => $dripBase->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Previous medication orders for the selected patient (excluding this visit).
     *
     * @return Collection<int, MedicationOrder>
     */
    #[Computed]
    public function medicationHistory(): Collection
    {
        if (! $this->showHistoryModal) {
            return new Collection;
        }

        $token = $this->selectedToken;

        if ($token?->patient_id === null) {
            return new Collection;
        }

        return MedicationOrder::query()
            ->with([
                'medicines',
                'injections',
                'drips.additives',
                'doctor',
                'prescribedBy',
                'queueToken.serviceQueue.service',
            ])
            ->where('patient_id', $token->patient_id)
            ->where('queue_token_id', '!=', $token->id)
            ->latest()
            ->limit(20)
            ->get();
    }

    /**
     * Select a patient token and load any existing pending order.
     */
    public function selectToken(int $tokenId): void
    {
        $token = $this->queue->firstWhere('id', $tokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer in the medication queue.'));

            return;
        }

        $this->selectedTokenId = $tokenId;
        $this->showHistoryModal = false;
        $this->activeOrderTab = 'medicines';
        $this->recheckMinutes = '15';
        $this->recheckNote = $token->activeRecheck?->note ?? '';
        $this->showRecheckForm = false;
        $this->resetValidation();
        $this->loadOrderForm($token);
    }

    /**
     * Show the recheck timer form.
     */
    public function openRecheckForm(): void
    {
        $this->showRecheckForm = true;
    }

    /**
     * Hide the recheck timer form.
     */
    public function closeRecheckForm(): void
    {
        $this->showRecheckForm = false;
        $this->resetValidation(['recheckMinutes', 'recheckNote']);
    }

    /**
     * Set a recheck timer for the selected patient.
     */
    public function setRecheck(): void
    {
        $token = $this->selectedToken;

        if ($token === null || $token->patient_id === null) {
            Flux::toast(variant: 'danger', text: __('Patient not found.'));
            $this->backToList();

            return;
        }

        $validated = $this->validate([
            'recheckMinutes' => ['required', 'integer', 'min:1', 'max:240'],
            'recheckNote' => ['nullable', 'string', 'max:255'],
        ]);

        DoctorRecheck::query()
            ->where('queue_token_id', $token->id)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);

        DoctorRecheck::create([
            'queue_token_id' => $token->id,
            'patient_id' => $token->patient_id,
            'set_by' => auth()->id(),
            'minutes' => (int) $validated['recheckMinutes'],
            'note' => filled($validated['recheckNote'] ?? null) ? $validated['recheckNote'] : null,
            'due_at' => now()->addMinutes((int) $validated['recheckMinutes']),
        ]);

        unset($this->queue, $this->selectedToken);

        Flux::toast(variant: 'success', text: __('Recheck timer set for :minutes minutes.', ['minutes' => $validated['recheckMinutes']]));
        $this->backToList();
    }

    /**
     * Clear the active recheck for a patient.
     */
    public function acknowledgeRecheck(int $tokenId): void
    {
        DoctorRecheck::query()
            ->where('queue_token_id', $tokenId)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);

        unset($this->queue, $this->selectedToken);

        Flux::toast(variant: 'success', text: __('Recheck cleared.'));
    }

    /**
     * Refresh the queue and toast for any newly due rechecks.
     */
    public function notifyDueRechecks(): void
    {
        unset($this->queue);

        $dueRechecks = DoctorRecheck::query()
            ->with('patient')
            ->due()
            ->whereNull('notified_at')
            ->get();

        foreach ($dueRechecks as $recheck) {
            $name = $recheck->patient?->name ?? __('Unknown');
            $note = filled($recheck->note) ? ' — '.$recheck->note : '';

            Flux::toast(
                variant: 'warning',
                text: __('Recheck due: :name:note (Again)', ['name' => $name, 'note' => $note]),
            );

            $recheck->update(['notified_at' => now()]);
        }

        unset($this->queue);
    }

    /**
     * Open the medication history modal for the selected patient.
     */
    public function openHistory(): void
    {
        if ($this->selectedToken?->patient_id === null) {
            Flux::toast(variant: 'danger', text: __('Patient not found.'));

            return;
        }

        $this->closeModals();
        $this->showHistoryModal = true;
        unset($this->medicationHistory);
    }

    /**
     * Close the medication history modal.
     */
    public function closeHistory(): void
    {
        $this->showHistoryModal = false;
    }

    /**
     * Return to the patient list.
     */
    public function backToList(): void
    {
        $this->selectedTokenId = null;
        $this->showHistoryModal = false;
        $this->showOrderPreviewModal = false;
        $this->resetOrderPreview();
        $this->notes = '';
        $this->complaintOrDiagnosis = '';
        $this->suggestedPrice = '';
        $this->dripServiceId = null;
        $this->recheckMinutes = '15';
        $this->recheckNote = '';
        $this->showRecheckForm = false;
        $this->medicationLines = [];
        $this->dripLines = [];
        $this->resetValidation();
    }

    public function switchOrderTab(string $tab): void
    {
        if (! in_array($tab, ['medicines', 'drips'], true)) {
            return;
        }

        $this->activeOrderTab = $tab;
        $this->ensureFirstRowForTab($tab);
    }

    /**
     * Add a blank row for the currently active order tab.
     */
    public function addRowForActiveTab(): void
    {
        match ($this->activeOrderTab) {
            'medicines' => $this->addMedicationLine(),
            'drips' => $this->addDripLine(),
            default => null,
        };
    }

    /**
     * Ensure the active tab has at least one blank row to fill.
     */
    private function ensureFirstRowForTab(string $tab): void
    {
        match ($tab) {
            'medicines' => $this->medicationLines === [] ? $this->addMedicationLine() : null,
            'drips' => $this->dripLines === [] ? $this->addDripLine() : null,
            default => null,
        };
    }

    public function addMedicationLine(): void
    {
        $this->medicationLines[] = [
            'selection' => null,
            'dose' => MedicineDose::OneZeroZero->value,
            'administration_type' => InjectionAdministrationType::Im->value,
            'comment' => '',
        ];
    }

    public function removeMedicationLine(int $index): void
    {
        unset($this->medicationLines[$index]);
        $this->medicationLines = array_values($this->medicationLines);
    }

    public function updatedMedicationLines(mixed $value, ?string $key): void
    {
        if (! is_string($key) || ! str_ends_with($key, '.selection')) {
            return;
        }

        $index = (int) explode('.', $key)[0];
        $selection = $this->medicationLines[$index]['selection'] ?? null;
        $selectionType = $this->medicationSelectionType($selection);
        $selectionId = $this->medicationSelectionId($selection);

        if ($selectionType === 'medicine' && $selectionId !== null) {
            $medicine = $this->medicines->firstWhere('id', $selectionId);

            if ($medicine !== null) {
                $this->medicationLines[$index]['dose'] = $medicine->default_dose->value;
            }
        }

        if ($selectionType === 'injection' && $selectionId !== null) {
            $injection = $this->injections->firstWhere('id', $selectionId);

            if ($injection !== null) {
                $this->medicationLines[$index]['administration_type'] = $injection->default_administration_type->value;
            }
        }
    }

    public function addDripLine(): void
    {
        $this->dripLines[] = [
            'drip_base_id' => null,
            'additives' => [
                [
                    'injection_id' => null,
                ],
                [
                    'injection_id' => null,
                ],
            ],
        ];
    }

    public function removeDripLine(int $index): void
    {
        unset($this->dripLines[$index]);
        $this->dripLines = array_values($this->dripLines);
    }

    public function addDripAdditive(int $dripIndex): void
    {
        if (! isset($this->dripLines[$dripIndex])) {
            return;
        }

        $this->dripLines[$dripIndex]['additives'][] = [
            'injection_id' => null,
        ];
    }

    public function removeDripAdditive(int $dripIndex, int $additiveIndex): void
    {
        if (! isset($this->dripLines[$dripIndex]['additives'][$additiveIndex])) {
            return;
        }

        unset($this->dripLines[$dripIndex]['additives'][$additiveIndex]);
        $this->dripLines[$dripIndex]['additives'] = array_values($this->dripLines[$dripIndex]['additives']);
    }

    /**
     * Validate the order and show it as it will appear at the ER station.
     */
    public function previewOrder(): void
    {
        $orderData = $this->validatedOrderData();

        if ($orderData === null) {
            return;
        }

        [
            'validated' => $validated,
            'medicineLines' => $medicineLines,
            'injectionLines' => $injectionLines,
            'dripLines' => $dripLines,
            'medicinesById' => $medicinesById,
            'injectionsById' => $injectionsById,
            'dripBasesById' => $dripBasesById,
        ] = $orderData;

        $this->closeModals();

        $this->orderPreview = [
            'medicines' => $medicineLines
                ->map(fn (array $line): ?array => $this->resolveMedicineLine($line, $medicinesById))
                ->filter()
                ->map(fn (array $line): array => [
                    'name' => $line['name'],
                    'dose' => MedicineDose::from($line['dose'])->label(),
                    'comment' => filled($line['comment'] ?? null) ? $line['comment'] : null,
                ])
                ->values()
                ->all(),
            'injections' => $injectionLines
                ->map(function (array $line) use ($injectionsById): ?array {
                    $resolved = $this->resolveInjection($line['injection_id'] ?? null, $injectionsById);

                    if ($resolved === null) {
                        return null;
                    }

                    return [
                        'name' => $resolved['name'],
                        'administration_type' => InjectionAdministrationType::from($line['administration_type'])->label(),
                        'comment' => filled($line['comment'] ?? null) ? $line['comment'] : null,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'drips' => $dripLines
                ->map(function (array $line) use ($dripBasesById, $injectionsById): ?array {
                    $dripBase = $dripBasesById->get((int) $line['drip_base_id']);

                    if ($dripBase === null || ! $dripBase->show_on_er) {
                        return null;
                    }

                    return [
                        'name' => $dripBase->name,
                        'additives' => collect($line['additives'] ?? [])
                            ->map(function (array $additive) use ($injectionsById): ?array {
                                $resolved = $this->resolveInjection($additive['injection_id'] ?? null, $injectionsById);

                                if ($resolved === null) {
                                    return null;
                                }

                                return [
                                    'name' => $resolved['name'],
                                ];
                            })
                            ->filter()
                            ->values()
                            ->all(),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'notes' => filled($validated['notes']) ? $validated['notes'] : null,
        ];

        $this->showOrderPreviewModal = true;
    }

    public function closeOrderPreview(): void
    {
        $this->showOrderPreviewModal = false;
        $this->resetOrderPreview();
    }

    /**
     * Only one modal may be open at a time, otherwise a stale flag reopens it on the next render.
     */
    private function closeModals(): void
    {
        $this->showHistoryModal = false;
        $this->showOrderPreviewModal = false;
        $this->resetOrderPreview();
    }

    /**
     * Save or update the medication order for the selected token.
     */
    public function save(): void
    {
        $this->saveOrder();
    }

    /**
     * Save the order, complete the current token, and call the next patient.
     */
    public function saveAndNext(): void
    {
        $this->saveOrder(advanceQueue: true);
    }

    /**
     * Save or update the selected patient's medication order.
     */
    private function saveOrder(bool $advanceQueue = false): void
    {
        $token = $this->selectedToken;

        if ($token === null || $token->patient_id === null) {
            Flux::toast(variant: 'danger', text: __('Patient not found.'));
            $this->backToList();

            return;
        }

        if (! in_array($token->status, ['waiting', 'serving'], true)) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer available for medication.'));
            $this->backToList();

            return;
        }

        if (! $token->serviceQueue?->service?->needs_medication) {
            Flux::toast(variant: 'danger', text: __('This service does not require medication.'));
            $this->backToList();

            return;
        }

        if ($advanceQueue && ! app(TokenDisplayService::class)->followsDoctorToken($token->serviceQueue)) {
            abort(403);
        }

        $existing = $token->medicationOrder;

        if ($existing !== null && $existing->status === MedicationOrderStatus::Administered) {
            Flux::toast(variant: 'danger', text: __('This order has already been administered and cannot be edited.'));
            $this->backToList();

            return;
        }

        $orderData = $this->validatedOrderData();

        if ($orderData === null) {
            return;
        }

        [
            'validated' => $validated,
            'medicineLines' => $medicineLines,
            'injectionLines' => $injectionLines,
            'dripLines' => $dripLines,
            'medicinesById' => $medicinesById,
            'injectionsById' => $injectionsById,
            'dripBasesById' => $dripBasesById,
        ] = $orderData;

        DB::transaction(function () use ($token, $validated, $medicineLines, $injectionLines, $dripLines, $medicinesById, $injectionsById, $dripBasesById, $existing): void {
            $order = $existing ?? new MedicationOrder([
                'queue_token_id' => $token->id,
                'patient_id' => $token->patient_id,
            ]);

            $order->fill([
                'doctor_id' => $token->serviceQueue?->doctor_id,
                'prescribed_by' => auth()->id(),
                'status' => MedicationOrderStatus::Pending,
                'complaint_or_diagnosis' => $validated['complaintOrDiagnosis'] !== '' ? $validated['complaintOrDiagnosis'] : null,
                'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
                'administered_by' => null,
                'administered_at' => null,
            ]);
            $order->save();

            $order->medicines()->delete();
            $order->injections()->delete();
            $order->drips()->delete();

            foreach ($medicineLines as $line) {
                $resolved = $this->resolveMedicineLine($line, $medicinesById);

                if ($resolved === null) {
                    continue;
                }

                $order->medicines()->create($resolved);
            }

            foreach ($injectionLines as $line) {
                $resolved = $this->resolveInjection($line['injection_id'] ?? null, $injectionsById);

                if ($resolved === null) {
                    continue;
                }

                $order->injections()->create([
                    'injection_id' => $resolved['injection_id'],
                    'administration_type' => $line['administration_type'],
                    'comment' => filled($line['comment'] ?? null) ? $line['comment'] : null,
                    'name' => $resolved['name'],
                ]);
            }

            foreach ($dripLines as $line) {
                $dripBase = $dripBasesById->get((int) $line['drip_base_id']);

                if ($dripBase === null) {
                    continue;
                }

                $drip = $order->drips()->create([
                    'drip_base_id' => $dripBase->id,
                    'name' => $dripBase->name,
                ]);

                foreach ($line['additives'] ?? [] as $additive) {
                    $resolved = $this->resolveInjection($additive['injection_id'] ?? null, $injectionsById);

                    if ($resolved === null) {
                        continue;
                    }

                    $drip->additives()->create([
                        'injection_id' => $resolved['injection_id'],
                        'name' => $resolved['name'],
                    ]);
                }
            }

            $this->syncDripCharge($order, $token, $validated);
        });

        unset($this->queue, $this->selectedToken);

        if ($advanceQueue) {
            $nextToken = app(TokenDisplayService::class)->callNext($token->serviceQueue);

            Flux::toast(variant: 'success', text: __('Medication order saved. Next patient called.'));

            if ($nextToken !== null) {
                $this->backToList();
                unset($this->queue, $this->selectedToken);
                $this->selectToken($nextToken->id);

                return;
            }

            $this->backToList();

            return;
        }

        Flux::toast(variant: 'success', text: __('Medication order saved.'));
        $this->backToList();
    }

    /**
     * Validate and resolve the entered order lines.
     *
     * @return array{
     *     validated: array<string, mixed>,
     *     medicineLines: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     injectionLines: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     dripLines: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     medicinesById: Collection<int, Medicine>,
     *     injectionsById: Collection<int, Injection>,
     *     dripBasesById: Collection<int, DripBase>
     * }|null
     */
    private function validatedOrderData(): ?array
    {
        $validated = $this->validate($this->orderRules());

        $medicationLines = collect($validated['medicationLines'] ?? [])
            ->filter(fn (array $line): bool => filled($line['selection'] ?? null))
            ->values();

        $medicineLines = $medicationLines
            ->filter(fn (array $line): bool => $this->medicationSelectionType($line['selection']) === 'medicine')
            ->map(fn (array $line): array => [
                'medicine_id' => $this->medicationSelectionValue($line['selection']),
                'dose' => $line['dose'],
                'comment' => $line['comment'] ?? '',
            ])
            ->values();

        $injectionLines = $medicationLines
            ->filter(fn (array $line): bool => $this->medicationSelectionType($line['selection']) === 'injection')
            ->map(fn (array $line): array => [
                'injection_id' => $this->medicationSelectionValue($line['selection']),
                'administration_type' => $line['administration_type'],
                'comment' => $line['comment'] ?? '',
            ])
            ->values();
        $dripLines = collect($validated['dripLines'] ?? [])
            ->filter(fn (array $line): bool => filled($line['drip_base_id'] ?? null))
            ->values();

        if ($medicineLines->isEmpty() && $injectionLines->isEmpty() && $dripLines->isEmpty()) {
            $this->addError('medicationLines', __('Add at least one medicine, injection, or drip.'));

            return null;
        }

        $medicinesById = Medicine::query()
            ->whereIn(
                'id',
                $medicineLines
                    ->pluck('medicine_id')
                    ->filter(fn (mixed $id): bool => is_numeric($id))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all()
            )
            ->get()
            ->keyBy('id');
        $injectionsById = Injection::query()
            ->whereIn(
                'id',
                $injectionLines->pluck('injection_id')
                    ->merge($dripLines->flatMap(fn (array $drip) => collect($drip['additives'] ?? [])->pluck('injection_id')))
                    ->filter(fn (mixed $id): bool => is_numeric($id))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all()
            )
            ->get()
            ->keyBy('id');
        $dripBasesById = DripBase::query()
            ->whereIn('id', $dripLines->pluck('drip_base_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return compact(
            'validated',
            'medicineLines',
            'injectionLines',
            'dripLines',
            'medicinesById',
            'injectionsById',
            'dripBasesById',
        );
    }

    private function resetOrderPreview(): void
    {
        $this->orderPreview = [
            'medicines' => [],
            'injections' => [],
            'drips' => [],
            'notes' => null,
        ];
    }

    /**
     * Create, update, or clear the pending drip charge for this order.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncDripCharge(MedicationOrder $order, QueueToken $token, array $validated): void
    {
        $pendingCharge = DripCharge::query()
            ->where('queue_token_id', $token->id)
            ->where('status', DripChargeStatus::Pending)
            ->first();

        if (! filled($validated['suggestedPrice'] ?? null)) {
            $pendingCharge?->delete();

            return;
        }

        $service = Service::query()
            ->active()
            ->where('is_drip', true)
            ->find($validated['dripServiceId'] ?? null);

        if ($service === null) {
            return;
        }

        $share = app(ResolveDripShareDoctor::class)->resolve($service, auth()->user());

        $attributes = [
            'patient_id' => $token->patient_id,
            'queue_token_id' => $token->id,
            'medication_order_id' => $order->id,
            'service_id' => $service->id,
            'doctor_id' => $share['doctor']?->id,
            'suggested_price' => $validated['suggestedPrice'],
            'doctor_share' => $share['doctor_share'],
            'status' => DripChargeStatus::Pending,
            'suggested_by' => auth()->id(),
        ];

        if ($pendingCharge !== null) {
            $pendingCharge->update($attributes);

            return;
        }

        DripCharge::create($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRules(): array
    {
        return [
            'complaintOrDiagnosis' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'suggestedPrice' => ['nullable', 'numeric', 'min:0'],
            'dripServiceId' => [
                'nullable',
                'required_with:suggestedPrice',
                'integer',
                Rule::exists('services', 'id')->where(fn ($query) => $query->where('is_drip', true)->where('is_active', true)),
            ],
            'medicationLines' => ['array'],
            'medicationLines.*.selection' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! filled($value)) {
                    return;
                }

                $selectionType = $this->medicationSelectionType($value);
                $selectionId = $this->medicationSelectionId($value);

                if ($selectionType === 'medicine' && $selectionId !== null) {
                    if (! Medicine::query()->whereKey($selectionId)->exists()) {
                        $fail(__('The selected medication is invalid.'));
                    }

                    return;
                }

                if ($selectionType === 'injection' && $selectionId !== null) {
                    if (! Injection::query()->whereKey($selectionId)->exists()) {
                        $fail(__('The selected medication is invalid.'));
                    }

                    return;
                }

                $name = $this->customLineName($value);

                if ($selectionType === null || $name === '' || mb_strlen($name) > 255) {
                    $fail(__('Medication name must be 255 characters or fewer.'));
                }
            }],
            'medicationLines.*.dose' => ['required', 'string', Rule::enum(MedicineDose::class)],
            'medicationLines.*.administration_type' => ['required', 'string', Rule::enum(InjectionAdministrationType::class)],
            'medicationLines.*.comment' => ['nullable', 'string', 'max:255'],
            'dripLines' => ['array'],
            'dripLines.*.drip_base_id' => ['nullable', 'integer', 'exists:drip_bases,id'],
            'dripLines.*.additives' => ['array'],
            'dripLines.*.additives.*.injection_id' => ['nullable', $this->injectionSelectionRule()],
        ];
    }

    /**
     * Accept either a catalog injection id or a name written by the doctor.
     */
    private function injectionSelectionRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! filled($value)) {
                return;
            }

            if (is_numeric($value)) {
                if (! Injection::query()->whereKey((int) $value)->exists()) {
                    $fail(__('The selected injection is invalid.'));
                }

                return;
            }

            $name = $this->customLineName($value);

            if ($name === '' || mb_strlen($name) > 255) {
                $fail(__('Injection name must be 255 characters or fewer.'));
            }
        };
    }

    private function medicationSelectionType(mixed $selection): ?string
    {
        if (! is_string($selection)) {
            return null;
        }

        if (preg_match('/^medicine:\d+$/', $selection) === 1 || str_starts_with($selection, 'custom:')) {
            return 'medicine';
        }

        return preg_match('/^injection:\d+$/', $selection) === 1 || str_starts_with($selection, 'custom-injection:')
            ? 'injection'
            : null;
    }

    private function medicationSelectionId(mixed $selection): ?int
    {
        if (! is_string($selection) || preg_match('/^(?:medicine|injection):(\d+)$/', $selection, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function medicationSelectionValue(mixed $selection): int|string|null
    {
        if (($selectionId = $this->medicationSelectionId($selection)) !== null) {
            return $selectionId;
        }

        if (is_string($selection) && str_starts_with($selection, 'custom-injection:')) {
            return 'custom:'.substr($selection, strlen('custom-injection:'));
        }

        return is_string($selection) && str_starts_with($selection, 'custom:') ? $selection : null;
    }

    /**
     * Resolve a written custom catalog name from the searchable select value.
     */
    private function customLineName(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        if (str_starts_with($value, 'custom-injection:')) {
            $value = substr($value, strlen('custom-injection:'));
        } elseif (str_starts_with($value, 'custom:')) {
            $value = substr($value, strlen('custom:'));
        }

        return trim($value);
    }

    /**
     * Turn a medicine form row into an order line payload.
     *
     * @param  array{medicine_id: int|string|null, dose: string, comment: string}  $line
     * @param  Collection<int, Medicine>  $medicinesById
     * @return array{medicine_id: int|null, dose: string, comment: string|null, name: string}|null
     */
    private function resolveMedicineLine(array $line, Collection $medicinesById): ?array
    {
        $raw = $line['medicine_id'] ?? null;

        if (is_numeric($raw)) {
            $medicine = $medicinesById->get((int) $raw);

            if ($medicine === null) {
                return null;
            }

            return [
                'medicine_id' => $medicine->id,
                'dose' => $line['dose'],
                'comment' => filled($line['comment'] ?? null) ? $line['comment'] : null,
                'name' => $medicine->name,
            ];
        }

        $name = $this->customLineName($raw);

        if ($name === '') {
            return null;
        }

        return [
            'medicine_id' => null,
            'dose' => $line['dose'],
            'comment' => filled($line['comment'] ?? null) ? $line['comment'] : null,
            'name' => $name,
        ];
    }

    /**
     * Turn an injection or drip additive selection into a catalog id and display name.
     *
     * @param  Collection<int, Injection>  $injectionsById
     * @return array{injection_id: int|null, name: string}|null
     */
    private function resolveInjection(mixed $raw, Collection $injectionsById): ?array
    {
        if (is_numeric($raw)) {
            $injection = $injectionsById->get((int) $raw);

            if ($injection === null) {
                return null;
            }

            return [
                'injection_id' => $injection->id,
                'name' => $injection->name,
            ];
        }

        $name = $this->customLineName($raw);

        if ($name === '') {
            return null;
        }

        return [
            'injection_id' => null,
            'name' => $name,
        ];
    }

    private function loadOrderForm(QueueToken $token): void
    {
        $order = $token->medicationOrder()
            ->with(['medicines', 'injections', 'drips.additives'])
            ->first();

        $pendingCharge = DripCharge::query()
            ->where('queue_token_id', $token->id)
            ->where('status', DripChargeStatus::Pending)
            ->first();

        $this->suggestedPrice = $pendingCharge !== null
            ? (string) $pendingCharge->suggested_price
            : '';
        $this->dripServiceId = $pendingCharge?->service_id
            ?? $this->dripServices->first()?->id;

        if ($order === null || $order->status === MedicationOrderStatus::Administered) {
            $this->notes = '';
            $this->complaintOrDiagnosis = '';
            $this->medicationLines = [];
            $this->dripLines = [];
            $this->ensureDefaultOrderLines();

            return;
        }

        $this->notes = $order->notes ?? '';
        $this->complaintOrDiagnosis = $order->complaint_or_diagnosis ?? '';
        $medicineLines = $order->medicines->map(fn ($line) => [
            'selection' => $line->medicine_id !== null ? 'medicine:'.$line->medicine_id : 'custom:'.$line->name,
            'dose' => $line->dose->value,
            'administration_type' => InjectionAdministrationType::Im->value,
            'comment' => $line->comment ?? '',
        ]);

        $injectionLines = $order->injections->map(fn ($line) => [
            'selection' => $line->injection_id !== null ? 'injection:'.$line->injection_id : 'custom-injection:'.$line->name,
            'dose' => MedicineDose::OneZeroZero->value,
            'administration_type' => $line->administration_type->value,
            'comment' => $line->comment ?? '',
        ]);

        $this->medicationLines = collect($medicineLines->all())
            ->merge($injectionLines->all())
            ->values()
            ->all();

        $this->dripLines = $order->drips->map(fn ($drip) => [
            'drip_base_id' => $drip->drip_base_id,
            'additives' => $drip->additives->map(fn ($additive) => [
                'injection_id' => $additive->injection_id ?? 'custom:'.$additive->name,
            ])->values()->all(),
        ])->values()->all();

        $this->ensureDefaultOrderLines();
    }

    /**
     * Pad a new or existing order with the rows doctors commonly need.
     */
    private function ensureDefaultOrderLines(): void
    {
        while (count($this->medicationLines) < 6) {
            $this->addMedicationLine();
        }

        if ($this->dripLines === []) {
            $this->addDripLine();
        }

        foreach (array_keys($this->dripLines) as $dripIndex) {
            while (count($this->dripLines[$dripIndex]['additives'] ?? []) < 2) {
                $this->addDripAdditive($dripIndex);
            }
        }
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4" wire:poll.10s="notifyDueRechecks">
    <div class="flex items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Medication') }}</flux:heading>
        @if ($selectedTokenId === null)
            <flux:badge color="zinc" size="lg">{{ $this->queue->count() }}</flux:badge>
        @endif
    </div>

    @if ($selectedTokenId === null)
        <div class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->queue as $token)
                @php($recheck = $token->activeRecheck)
                <x-paper-slip
                    as="button"
                    type="button"
                    :token="$token->token_number"
                    :tone="$recheck?->isDue() ? 'accent' : 'default'"
                    wire:key="medication-token-{{ $token->id }}"
                    wire:click="selectToken({{ $token->id }})"
                    class="min-h-48 active:scale-[0.99] hover:-translate-y-0.5"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p class="truncate text-lg font-semibold text-zinc-900">
                            {{ $token->patient?->name ?? __('Unknown') }}
                        </p>
                        @if ($recheck?->isDue())
                            <flux:badge size="sm" color="amber">{{ __('Again') }}</flux:badge>
                        @elseif ($recheck)
                            <flux:badge size="sm" color="zinc">{{ __('Recheck :time', ['time' => $recheck->due_at->timezone(config('app.timezone'))->format('h:i A')]) }}</flux:badge>
                        @endif
                    </div>
                    <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                        {{ $token->patient?->mrn ?? __('No MRN') }}
                        · {{ $token->serviceQueue?->service?->name }}
                    </p>
                    @if ($token->medicationOrder || $recheck?->isDue())
                        <div class="mt-1 border-t border-dashed border-zinc-400/70 pt-2 text-xs text-zinc-600">
                            @if ($token->medicationOrder)
                                {{ $token->medicationOrder->status->label() }}
                            @endif
                            @if ($recheck?->isDue())
                                {{ $token->medicationOrder ? ' · ' : '' }}{{ __('Again') }}{{ filled($recheck->note) ? ' — '.$recheck->note : '' }}
                            @endif
                        </div>
                    @endif
                    <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                        {{ __('Tap to prescribe') }}
                    </p>
                </x-paper-slip>
            @empty
                <div class="col-span-full flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
                    <flux:icon name="beaker" class="size-10 text-zinc-400" />
                    <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('No patients need medication') }}</p>
                    <p class="text-sm text-zinc-500">{{ __('Waiting or serving patients for services that need medication will appear here.') }}</p>
                </div>
            @endforelse
        </div>
    @else
        @php($token = $this->selectedToken)
        @php($activeRecheck = $token?->activeRecheck)
        <div class="sticky top-0 z-10 -mx-4 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900 sm:mx-0 sm:rounded-xl sm:border">
            <div class="flex items-center gap-3">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-lg font-bold text-white dark:bg-white dark:text-zinc-900">
                    {{ $token?->token_number }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ $token?->patient?->name ?? __('Unknown') }}
                        @if ($activeRecheck?->isDue())
                            <flux:badge size="sm" color="amber" class="ms-1 align-middle">{{ __('Again') }}</flux:badge>
                        @endif
                    </p>
                    <p class="truncate text-sm text-zinc-500">
                        {{ $token?->patient?->mrn ?? __('No MRN') }}
                        · {{ $token?->serviceQueue?->service?->name }}
                    </p>
                </div>
                <flux:button type="button" size="sm" variant="ghost" icon="clock" wire:click="openHistory">
                    {{ __('History') }}
                </flux:button>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mb-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <flux:heading size="sm">{{ __('Vitals') }}</flux:heading>
                    @if ($activeRecheck?->isDue())
                        <flux:badge color="amber">{{ __('Again') }}</flux:badge>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @unless ($showRecheckForm)
                        <flux:button type="button" size="sm" variant="ghost" icon="clock" wire:click="openRecheckForm">
                            {{ __('Set recheck timer') }}
                            @if ($activeRecheck)
                                <flux:badge size="sm" color="{{ $activeRecheck->isDue() ? 'amber' : 'zinc' }}" class="ms-1">
                                    {{ $activeRecheck->isDue() ? __('Again') : $activeRecheck->timeRemainingLabel() }}
                                </flux:badge>
                            @endif
                        </flux:button>
                    @else
                        <flux:button type="button" size="sm" variant="ghost" wire:click="closeRecheckForm">
                            {{ __('Hide') }}
                        </flux:button>
                    @endunless
                </div>
            </div>
            @if ($token?->vitals->isNotEmpty())
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($token->vitals as $vital)
                        <x-paper-slip wire:key="vital-{{ $vital->id }}" class="shadow-sm">
                            <div class="flex items-center justify-between gap-2 border-b border-dashed border-zinc-400/70 pb-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-600">
                                    {{ $vital->created_at->timezone(config('app.timezone'))->format('d M, g:i A') }}
                                </p>
                                <p class="truncate text-xs text-zinc-500">{{ $vital->recordedBy?->name ?? '—' }}</p>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Temp') }}</p>
                                    <p class="mt-1 font-mono text-lg font-bold text-zinc-900">{{ $vital->temperature }}°F</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('BP') }}</p>
                                    <p class="mt-1 font-mono text-lg font-bold text-zinc-900">{{ $vital->bp_systolic }}/{{ $vital->bp_diastolic }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('BSR') }}</p>
                                    <p class="mt-1 font-mono text-lg font-bold text-zinc-900">{{ $vital->bsr ?? '—' }}</p>
                                </div>
                            </div>
                        </x-paper-slip>
                    @endforeach
                </div>
                @if ($activeRecheck?->isDue())
                    <p class="mt-2 text-sm font-medium text-amber-600 dark:text-amber-400">{{ __('Again — recheck due') }}</p>
                @endif
            @else
                <p class="text-sm text-zinc-500">{{ __('No vitals recorded for this visit.') }}</p>
                @if ($activeRecheck?->isDue())
                    <p class="mt-2 text-sm font-medium text-amber-600 dark:text-amber-400">{{ __('Again — recheck due') }}</p>
                @endif
            @endif

            @if ($showRecheckForm)
                <div class="mt-4 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    @if ($activeRecheck)
                        <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-300">
                            @if ($activeRecheck->isDue())
                                {{ __('Due now') }}{{ filled($activeRecheck->note) ? ' — '.$activeRecheck->note : '' }}
                            @else
                                {{ $activeRecheck->timeRemainingLabel() }}
                                · {{ __('Due at :time', ['time' => $activeRecheck->due_at->timezone(config('app.timezone'))->format('h:i A')]) }}
                                {{ filled($activeRecheck->note) ? ' — '.$activeRecheck->note : '' }}
                            @endif
                        </p>
                        <div class="mb-4">
                            <flux:button type="button" size="sm" variant="ghost" wire:click="acknowledgeRecheck({{ $token->id }})">
                                {{ __('Clear recheck') }}
                            </flux:button>
                        </div>
                    @endif
                    <form wire:submit="setRecheck" class="grid gap-3 sm:grid-cols-12">
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Minutes') }}</flux:label>
                            <flux:input wire:model="recheckMinutes" type="number" min="1" max="240" required />
                            <flux:error name="recheckMinutes" />
                        </flux:field>
                        <flux:field class="sm:col-span-6">
                            <flux:label>{{ __('Note') }}</flux:label>
                            <flux:input wire:model="recheckNote" type="text" placeholder="{{ __('e.g. Check BP again') }}" />
                            <flux:error name="recheckNote" />
                        </flux:field>
                        <div class="flex items-end sm:col-span-3">
                            <flux:button type="submit" variant="primary" class="w-full" icon="clock">
                                {{ __('Set timer') }}
                            </flux:button>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        <flux:field>
            <flux:label>{{ __('Patient complaint / diagnosis') }}</flux:label>
            <flux:textarea
                wire:model="complaintOrDiagnosis"
                rows="2"
                placeholder="{{ __('Enter the patient complaint or diagnosis') }}"
            />
            <flux:error name="complaintOrDiagnosis" />
        </flux:field>

        <div class="border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-4">
                @foreach (['medicines' => __('Medications'), 'drips' => __('Drips')] as $tab => $label)
                    <button
                        type="button"
                        wire:click="switchOrderTab('{{ $tab }}')"
                        class="cursor-pointer border-b-2 px-1 pb-2 text-sm font-medium transition-colors {{ $activeOrderTab === $tab ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        <form
            wire:submit="previewOrder"
            class="flex flex-1 flex-col gap-4"
            x-data="{
                navigate(direction) {
                    const rows = Array.from(this.$el.querySelectorAll('[data-nav-row]'))
                        .map((row) => Array.from(row.querySelectorAll('[data-nav-field]')))
                        .filter((fields) => fields.length > 0);

                    if (rows.length === 0) {
                        return;
                    }

                    const current = document.activeElement?.closest('[data-nav-field]');
                    const rowIndex = rows.findIndex((fields) => fields.includes(current));

                    if (rowIndex === -1) {
                        this.focusField(rows[0][0]);

                        return;
                    }

                    if (direction === 'up' || direction === 'down') {
                        const target = rows[rowIndex + (direction === 'down' ? 1 : -1)];

                        if (! target) {
                            return;
                        }

                        const column = Math.min(rows[rowIndex].indexOf(current), target.length - 1);

                        this.focusField(target[column]);

                        return;
                    }

                    const fields = rows.flat();

                    this.focusField(fields[fields.indexOf(current) + (direction === 'right' ? 1 : -1)]);
                },
                focusField(field) {
                    field?.querySelector('input:not([type=hidden]), select, textarea, button')?.focus();
                },
            }"
            @keydown.shift.enter.prevent="$wire.addRowForActiveTab()"
            @keydown.alt.arrow-up.prevent="navigate('up')"
            @keydown.alt.arrow-down.prevent="navigate('down')"
            @keydown.alt.arrow-left.prevent="navigate('left')"
            @keydown.alt.arrow-right.prevent="navigate('right')"
        >
            @if ($activeOrderTab === 'medicines')
                <div class="space-y-3">
                    <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        @foreach ($medicationLines as $index => $line)
                            @php($hasMedication = filled($line['selection'] ?? null))
                            @php($isInjection = str_starts_with($line['selection'] ?? '', 'injection:') || str_starts_with($line['selection'] ?? '', 'custom-injection:'))
                            <div wire:key="medication-line-{{ $index }}" data-nav-row class="grid gap-2 sm:grid-cols-12">
                                <div class="{{ $hasMedication ? 'sm:col-span-5' : 'sm:col-span-11' }}" data-nav-field>
                                    <x-searchable-select
                                        wire:model.live="medicationLines.{{ $index }}.selection"
                                        :options="$this->medicationOptions"
                                        :placeholder="__('Search medicine or injection')"
                                        allow-custom
                                    />
                                    <flux:error name="medicationLines.{{ $index }}.selection" />
                                </div>
                                @if ($hasMedication)
                                    <div class="sm:col-span-3" data-nav-field>
                                        @if ($isInjection)
                                            <flux:select wire:model="medicationLines.{{ $index }}.administration_type" aria-label="{{ __('Administration type') }}">
                                                @foreach (\App\Enums\InjectionAdministrationType::cases() as $type)
                                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                @endforeach
                                            </flux:select>
                                            <flux:error name="medicationLines.{{ $index }}.administration_type" />
                                        @else
                                            <flux:select wire:model="medicationLines.{{ $index }}.dose" aria-label="{{ __('Timing') }}">
                                                @foreach (\App\Enums\MedicineDose::cases() as $dose)
                                                    <option value="{{ $dose->value }}">{{ $dose->label() }}</option>
                                                @endforeach
                                            </flux:select>
                                            <flux:error name="medicationLines.{{ $index }}.dose" />
                                        @endif
                                    </div>
                                    <div class="sm:col-span-3" data-nav-field>
                                        <flux:input wire:model="medicationLines.{{ $index }}.comment" type="text" placeholder="{{ __('Comment') }}" />
                                        <flux:error name="medicationLines.{{ $index }}.comment" />
                                    </div>
                                @endif
                                <div class="flex items-start sm:col-span-1">
                                    <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeMedicationLine({{ $index }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <flux:error name="medicationLines" />
                    <flux:tooltip :content="__('Shift+Enter')" position="top">
                        <flux:button type="button" variant="ghost" icon="plus" wire:click="addMedicationLine">{{ __('Add medication') }}</flux:button>
                    </flux:tooltip>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($dripLines as $dripIndex => $drip)
                        <div wire:key="drip-line-{{ $dripIndex }}" class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="grid gap-2 sm:grid-cols-12" data-nav-row>
                                <div class="sm:col-span-11" data-nav-field>
                                    <x-searchable-select
                                        wire:model.live="dripLines.{{ $dripIndex }}.drip_base_id"
                                        :options="$this->dripBaseOptions"
                                        :placeholder="__('Search drip base')"
                                    />
                                </div>
                                <div class="flex items-start sm:col-span-1">
                                    <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeDripLine({{ $dripIndex }})" />
                                </div>
                            </div>

                            <div class="space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Additives') }}</p>
                                @foreach ($drip['additives'] ?? [] as $additiveIndex => $additive)
                                    <div wire:key="drip-{{ $dripIndex }}-additive-{{ $additiveIndex }}" data-nav-row class="grid gap-2 sm:grid-cols-12">
                                        <div class="sm:col-span-11" data-nav-field>
                                            <x-searchable-select
                                                wire:model="dripLines.{{ $dripIndex }}.additives.{{ $additiveIndex }}.injection_id"
                                                :options="$this->injectionOptions"
                                                :placeholder="__('Search injection or type a new name')"
                                                allow-custom
                                            />
                                        </div>
                                        <div class="flex items-start sm:col-span-1">
                                            <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeDripAdditive({{ $dripIndex }}, {{ $additiveIndex }})" />
                                        </div>
                                    </div>
                                @endforeach
                                <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addDripAdditive({{ $dripIndex }})">
                                    {{ __('Add additive') }}
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                    <flux:tooltip :content="__('Shift+Enter')" position="top">
                        <flux:button type="button" variant="ghost" icon="plus" wire:click="addDripLine">{{ __('Add drip') }}</flux:button>
                    </flux:tooltip>

                    @if ($this->dripServices->isNotEmpty())
                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:heading size="sm" class="mb-3">{{ __('Drip charge') }}</flux:heading>
                            <div class="grid gap-3 sm:grid-cols-2" data-nav-row>
                                @if ($this->dripServices->count() > 1)
                                    <flux:field data-nav-field>
                                        <flux:label>{{ __('Drip service') }}</flux:label>
                                        <flux:select wire:model="dripServiceId">
                                            <option value="">{{ __('Select drip service') }}</option>
                                            @foreach ($this->dripServices as $dripService)
                                                <option value="{{ $dripService->id }}">{{ $dripService->name }}</option>
                                            @endforeach
                                        </flux:select>
                                        <flux:error name="dripServiceId" />
                                    </flux:field>
                                @endif

                                <flux:field data-nav-field class="{{ $this->dripServices->count() > 1 ? '' : 'sm:col-span-2' }}">
                                    <flux:label>{{ __('Suggested price') }}</flux:label>
                                    <flux:input
                                        wire:model="suggestedPrice"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="{{ __('Optional') }}"
                                    />
                                    <flux:error name="suggestedPrice" />
                                </flux:field>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <div data-nav-row>
                <flux:field data-nav-field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="2" />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-auto flex flex-col gap-3 pt-2">
                <flux:button type="submit" variant="primary" class="h-12 w-full text-base font-semibold">
                    {{ __('Save order') }}
                </flux:button>
                @if ($this->advancesDisplayToken)
                    <flux:button type="button" variant="primary" wire:click="saveAndNext" icon="arrow-right" class="h-12 w-full text-base font-semibold">
                        {{ __('Save & Next Patient') }}
                    </flux:button>
                @endif
                <flux:button type="button" variant="ghost" wire:click="backToList" class="w-full">
                    {{ __('Back to list') }}
                </flux:button>
            </div>
        </form>
    @endif

    <flux:modal name="medication-order-preview" wire:model="showOrderPreviewModal" class="w-full max-w-xl">
        <div class="space-y-4">
            <div>
                <flux:heading level="2">{{ __('ER order preview') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Review how this order will appear at the ER station before sending it.') }}</flux:text>
            </div>

            <x-paper-slip
                :token="$this->selectedToken?->token_number"
                class="mx-auto w-full max-w-lg"
            >
                <div class="space-y-1">
                    <p class="truncate text-lg font-semibold text-zinc-900">{{ $this->selectedToken?->patient?->name ?? __('Unknown') }}</p>
                    <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                        {{ $this->selectedToken?->patient?->mrn ?? __('No MRN') }}
                        · {{ $this->selectedToken?->serviceQueue?->service?->name }}
                    </p>
                </div>

                <div class="space-y-4 border-t border-dashed border-zinc-400/70 pt-3">
                    <div>
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Medicines') }}</p>
                        @forelse ($orderPreview['medicines'] as $index => $medicine)
                            <p wire:key="preview-medicine-{{ $index }}" class="mb-2 text-sm text-zinc-800">
                                {{ $medicine['name'] }}
                                <span class="text-zinc-500">
                                    — {{ $medicine['dose'] }}
                                    @if (filled($medicine['comment'] ?? null))
                                        · {{ $medicine['comment'] }}
                                    @endif
                                </span>
                            </p>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </div>

                    <div class="border-t border-dashed border-zinc-400/70 pt-3">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Injections') }}</p>
                        @forelse ($orderPreview['injections'] as $index => $injection)
                            <p wire:key="preview-injection-{{ $index }}" class="mb-2 text-sm text-zinc-800">
                                {{ $injection['name'] }}
                                <span class="text-zinc-500">
                                    — {{ $injection['administration_type'] }}
                                    @if (filled($injection['comment'] ?? null))
                                        · {{ $injection['comment'] }}
                                    @endif
                                </span>
                            </p>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </div>

                    @if ($orderPreview['drips'] !== [])
                        <div class="border-t border-dashed border-zinc-400/70 pt-3">
                            <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Drips') }}</p>
                            @foreach ($orderPreview['drips'] as $index => $drip)
                                <div wire:key="preview-drip-{{ $index }}" class="mb-2">
                                    <p class="text-sm font-medium text-zinc-800">
                                        {{ $drip['name'] }}
                                    </p>
                                    @foreach ($drip['additives'] as $additiveIndex => $additive)
                                        <p wire:key="preview-drip-{{ $index }}-additive-{{ $additiveIndex }}" class="ms-3 text-sm text-zinc-600">
                                            + {{ $additive['name'] }}
                                        </p>
                                    @endforeach
                                </div>
                            @endforeach
                            <p class="text-xs text-zinc-500">{{ __('Start and complete drips at the Drip Station.') }}</p>
                        </div>
                    @endif

                    @if (filled($orderPreview['notes']))
                        <div class="border-t border-dashed border-zinc-400/70 pt-3">
                            <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Notes') }}</p>
                            <p class="whitespace-pre-line text-sm text-zinc-800">{{ $orderPreview['notes'] }}</p>
                        </div>
                    @endif
                </div>
            </x-paper-slip>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeOrderPreview">
                    {{ __('Edit order') }}
                </flux:button>
                <flux:button type="button" variant="primary" wire:click="save">
                    {{ __('Confirm and send to ER') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="medication-history" wire:model="showHistoryModal" class="w-full max-w-2xl">
        <div class="space-y-4">
            <flux:heading level="2">{{ __('Medication history') }}</flux:heading>
            <p class="text-sm text-zinc-500">
                {{ $this->selectedToken?->patient?->name ?? __('Unknown') }}
                · {{ $this->selectedToken?->patient?->mrn ?? __('No MRN') }}
            </p>

            <div class="max-h-[70vh] space-y-4 overflow-y-auto pe-1">
                @forelse ($this->medicationHistory as $order)
                    <div wire:key="history-order-{{ $order->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                                    {{ $order->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                </p>
                                <p class="text-xs text-zinc-500">
                                    {{ $order->queueToken?->serviceQueue?->service?->name ?? __('Unknown service') }}
                                    @if ($order->doctor)
                                        · {{ $order->doctor->name }}
                                    @endif
                                </p>
                            </div>
                            <flux:badge size="sm" color="{{ $order->status === \App\Enums\MedicationOrderStatus::Administered ? 'green' : 'zinc' }}">
                                {{ $order->status->label() }}
                            </flux:badge>
                        </div>

                        @if ($order->complaint_or_diagnosis)
                            <p class="mb-2 text-sm text-zinc-500">
                                <span class="font-medium">{{ __('Complaint / diagnosis:') }}</span>
                                {{ $order->complaint_or_diagnosis }}
                            </p>
                        @endif

                        @if ($order->medicines->isNotEmpty())
                            <div class="mb-2">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Medicines') }}</p>
                                @foreach ($order->medicines as $medicine)
                                    <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                        {{ $medicine->name }}
                                        <span class="text-zinc-500">
                                            — {{ $medicine->dose->label() }}
                                            @if (filled($medicine->comment))
                                                · {{ $medicine->comment }}
                                            @endif
                                        </span>
                                    </p>
                                @endforeach
                            </div>
                        @endif

                        @if ($order->injections->isNotEmpty())
                            <div class="mb-2">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Injections') }}</p>
                                @foreach ($order->injections as $injection)
                                    <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                        {{ $injection->name }}
                                        <span class="text-zinc-500">
                                            — {{ $injection->administration_type->label() }}
                                            @if (filled($injection->comment))
                                                · {{ $injection->comment }}
                                            @endif
                                        </span>
                                    </p>
                                @endforeach
                            </div>
                        @endif

                        @if ($order->drips->isNotEmpty())
                            <div class="mb-2">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Drips') }}</p>
                                @foreach ($order->drips as $drip)
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                        {{ $drip->name }}
                                    </p>
                                    @foreach ($drip->additives as $additive)
                                        <p class="ms-3 text-sm text-zinc-500">
                                            + {{ $additive->name }}
                                        </p>
                                    @endforeach
                                @endforeach
                            </div>
                        @endif

                        @if ($order->notes)
                            <p class="mt-2 text-sm text-zinc-500">
                                <span class="font-medium">{{ __('Notes:') }}</span> {{ $order->notes }}
                            </p>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-600">
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('No previous medication records') }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Past prescriptions for this patient will appear here.') }}</p>
                    </div>
                @endforelse
            </div>

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeHistory">
                    {{ __('Close') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
