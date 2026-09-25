<?php

use App\Actions\RecallMedicationOrder;
use App\Actions\ResolveDripShareDoctor;
use App\Enums\DripChargeStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\TokenResetType;
use App\Models\DripBase;
use App\Models\DripCharge;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Services\TokenDisplayService;
use App\Support\PriceShorthand;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Medication')] class extends Component
{
    public ?int $selectedTokenId = null;

    public bool $showHistoryModal = false;

    public bool $showMedOrdersModal = false;

    public bool $showRepeatConflictModal = false;

    public bool $showOrderPreviewModal = false;

    public bool $showDoseModal = false;

    public ?int $doseDripIndex = null;

    public bool $showRecallModal = false;

    public bool $showFulfilledRecallOptions = false;

    public ?int $selectedRecallOrderId = null;

    public ?int $pendingRepeatOrderId = null;

    public ?int $selectedBrowseOrderId = null;

    public string $medOrdersDate = '';

    public string $medOrdersSearch = '';

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

    /**
     * Whether medications are picked with searchable selects (`typing`) or catalog badges (`visual`).
     */
    #[Session(key: 'medication-order-input-mode')]
    public string $orderInputMode = 'visual';

    public bool $showWrittenMedicationInput = false;

    public string $writtenMedicationName = '';

    public string $notes = '';

    public string $complaintOrDiagnosis = '';

    public string $suggestedPrice = '';

    public ?int $dripServiceId = null;

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
     * @var list<array{
     *     drip_base_id: int|string|null,
     *     dose: string,
     *     additives: list<array{injection_id: int|string|null, dose: string}>
     * }>
     */
    public array $dripLines = [];

    /**
     * The latest shift (open, or just closed between shifts) and the one before it.
     * Patients stay on the list across one shift change so closing a shift does not drop them.
     *
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function recentShifts(): Collection
    {
        $latestShift = Shift::current() ?? Shift::query()->latest('opened_at')->first();

        if ($latestShift === null) {
            return new Collection;
        }

        $previousShift = Shift::query()
            ->whereKeyNot($latestShift->id)
            ->where('opened_at', '<', $latestShift->opened_at)
            ->latest('opened_at')
            ->first();

        return new Collection(array_values(array_filter([$latestShift, $previousShift])));
    }

    /**
     * Limit a service queue query to queues from the recent shifts, open or closed.
     *
     * @param  Builder<ServiceQueue>  $query
     */
    private function whereInRecentShifts(Builder $query): void
    {
        $query->where(function (Builder $shiftQuery): void {
            foreach ($this->recentShifts as $shift) {
                $shiftQuery->orWhere(fn (Builder $inner) => $inner->forShift($shift));
            }
        });
    }

    /**
     * Whether a listed token came from the shift before the latest one, where token numbers restarted.
     */
    public function isFromPreviousShift(QueueToken $token): bool
    {
        $latestShift = $this->recentShifts->first();
        $queue = $token->serviceQueue;

        if ($latestShift === null || $queue === null || $this->recentShifts->count() < 2) {
            return false;
        }

        $belongsToLatest = $queue->shift_id === $latestShift->id
            || ($queue->reset_type === TokenResetType::Daily
                && $queue->date?->toDateString() === $latestShift->opened_at->toDateString());

        return ! $belongsToLatest;
    }

    /**
     * Waiting/serving tokens that need medication from the latest shift and the one before it.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function queue(): Collection
    {
        if ($this->recentShifts->isEmpty()) {
            return new Collection;
        }

        return QueueToken::query()
            ->with(['patient.family', 'serviceQueue.service', 'serviceQueue.doctor', 'vital', 'vitals.recordedBy', 'medicationOrder'])
            ->whereNull('medication_dismissed_at')
            ->where(function ($query): void {
                $query->whereIn('status', ['waiting', 'serving'])
                    ->orWhere(function ($servedQuery): void {
                        $servedQuery->where('status', 'served')
                            ->whereHas(
                                'medicationOrder',
                                fn ($orderQuery) => $orderQuery->where('status', MedicationOrderStatus::Draft)
                            );
                    });
            })
            ->where(function ($query): void {
                $query->whereDoesntHave('medicationOrder')
                    ->orWhereHas(
                        'medicationOrder',
                        fn ($orderQuery) => $orderQuery->where('status', MedicationOrderStatus::Draft)
                    );
            })
            ->whereHas('serviceQueue', function (Builder $query): void {
                $this->whereInRecentShifts($query);
                $query->whereHas('service', fn ($serviceQuery) => $serviceQuery->where('needs_medication', true));
            })
            ->orderByRaw('arrived_at is null')
            ->orderBy('arrived_at')
            ->orderBy('token_number')
            ->get();
    }

    /**
     * Latest submitted medication orders that can be recalled from the recent shifts.
     *
     * @return Collection<int, MedicationOrder>
     */
    #[Computed]
    public function recallableOrders(): Collection
    {
        if (! $this->showRecallModal || $this->recentShifts->isEmpty()) {
            return new Collection;
        }

        $orders = MedicationOrder::query()
            ->with(['patient.family', 'queueToken.serviceQueue.service'])
            ->whereHas('queueToken', fn ($query) => $query->whereIn('status', ['waiting', 'serving', 'served']))
            ->whereHas(
                'queueToken.serviceQueue',
                fn (Builder $query) => $this->whereInRecentShifts($query)
            )
            ->latest('id')
            ->get()
            ->unique('queue_token_id')
            ->filter(fn (MedicationOrder $order): bool => $order->status !== MedicationOrderStatus::Draft)
            ->values();

        return new Collection($orders->all());
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
            ?? QueueToken::with(['patient.family', 'serviceQueue.service', 'serviceQueue.doctor', 'vital', 'vitals.recordedBy', 'medicationOrder.medicines', 'medicationOrder.injections', 'medicationOrder.drips.additives'])
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
            && $queue->status === 'open'
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
        return Medicine::query()->active()->orderByRaw('lower(name)')->orderBy('name')->get();
    }

    /**
     * Active injection catalog.
     *
     * @return Collection<int, Injection>
     */
    #[Computed]
    public function injections(): Collection
    {
        return Injection::query()->active()->orderByRaw('lower(name)')->orderBy('name')->get();
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
                $label = $medicine->catalogLabel();

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
     * Display names for the filled order rows, keyed by row index.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function medicationLineNames(): array
    {
        $names = [];

        foreach ($this->medicationLines as $index => $line) {
            $selection = $line['selection'] ?? null;

            if (! filled($selection)) {
                continue;
            }

            $selectionId = $this->medicationSelectionId($selection);

            $names[$index] = match ($this->medicationSelectionType($selection)) {
                'medicine' => $selectionId !== null
                    ? ($this->medicines->firstWhere('id', $selectionId)?->name ?? '')
                    : $this->customLineName($selection),
                'injection' => $selectionId !== null
                    ? ($this->injections->firstWhere('id', $selectionId)?->name ?? '')
                    : $this->customLineName($selection),
                default => $this->customLineName($selection),
            };
        }

        return $names;
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
     * Display names for the filled drip rows, keyed by row index.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function dripLineNames(): array
    {
        $names = [];

        foreach ($this->dripLines as $index => $line) {
            $dripBaseId = $line['drip_base_id'] ?? null;

            if (! filled($dripBaseId)) {
                continue;
            }

            $names[$index] = is_numeric($dripBaseId)
                ? ($this->dripBases->firstWhere('id', (int) $dripBaseId)?->name ?? '')
                : $this->customLineName($dripBaseId);
        }

        return $names;
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
     * Medication orders for the med-orders browse modal (any patient on the chosen date).
     *
     * @return Collection<int, MedicationOrder>
     */
    #[Computed]
    public function browseableMedOrders(): Collection
    {
        if (! $this->showMedOrdersModal || $this->medOrdersDate === '') {
            return new Collection;
        }

        $search = trim($this->medOrdersSearch);

        return MedicationOrder::query()
            ->with([
                'patient.family',
                'medicines',
                'injections',
                'drips.additives',
                'doctor',
                'queueToken.serviceQueue.service',
            ])
            ->whereIn('status', [MedicationOrderStatus::Pending, MedicationOrderStatus::Administered])
            ->when(
                $this->selectedTokenId !== null,
                fn ($query) => $query->where('queue_token_id', '!=', $this->selectedTokenId)
            )
            ->whereHas(
                'queueToken.serviceQueue',
                fn ($query) => $query->whereDate('date', $this->medOrdersDate)
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->whereHas(
                        'patient',
                        fn ($patientQuery) => $patientQuery->where(function ($patientInner) use ($search): void {
                            $patientInner->where('name', 'like', '%'.$search.'%')
                                ->orWhere('mrn', 'like', '%'.$search.'%');
                        })
                    )->orWhereHas(
                        'queueToken',
                        fn ($tokenQuery) => $tokenQuery->where('token_number', $search)
                    );
                });
            })
            ->latest()
            ->limit(50)
            ->get();
    }

    /**
     * The order currently expanded in the med-orders browse modal.
     */
    #[Computed]
    public function selectedBrowseOrder(): ?MedicationOrder
    {
        if ($this->selectedBrowseOrderId === null || ! $this->showMedOrdersModal) {
            return null;
        }

        return $this->browseableMedOrders->firstWhere('id', $this->selectedBrowseOrderId)
            ?? MedicationOrder::query()
                ->with([
                    'patient.family',
                    'medicines',
                    'injections',
                    'drips.additives',
                    'doctor',
                    'queueToken.serviceQueue.service',
                ])
                ->find($this->selectedBrowseOrderId);
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
        $this->showMedOrdersModal = false;
        $this->showRepeatConflictModal = false;
        $this->pendingRepeatOrderId = null;
        $this->selectedBrowseOrderId = null;
        $this->showWrittenMedicationInput = false;
        $this->writtenMedicationName = '';
        $this->resetValidation();
        $this->loadOrderForm($token);
    }

    /**
     * Remove a patient who needs no medication from the list, leaving their token untouched.
     */
    public function dismissFromQueue(int $tokenId): void
    {
        $token = $this->queue->firstWhere('id', $tokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer in the medication queue.'));

            return;
        }

        if ($token->medicationOrder !== null) {
            Flux::toast(variant: 'danger', text: __('This patient has a recalled order. Open it and save it instead.'));

            return;
        }

        $token->update([
            'medication_dismissed_at' => now(),
            'medication_dismissed_by' => auth()->id(),
        ]);

        unset($this->queue);

        Flux::toast(variant: 'success', text: __(':name dismissed.', ['name' => $token->patient?->name ?? __('Patient')]));
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
     * Browse medication orders from any visit by date (for unlinked patients).
     */
    public function openMedOrders(): void
    {
        if ($this->selectedToken === null) {
            Flux::toast(variant: 'danger', text: __('Patient not found.'));

            return;
        }

        $this->closeModals();
        $this->medOrdersDate = $this->medOrdersDate !== ''
            ? $this->medOrdersDate
            : now()->timezone(config('app.timezone'))->toDateString();
        $this->showMedOrdersModal = true;
        $this->selectedBrowseOrderId = null;
        unset($this->browseableMedOrders, $this->selectedBrowseOrder);
    }

    public function closeMedOrders(): void
    {
        $this->showMedOrdersModal = false;
        $this->selectedBrowseOrderId = null;
        unset($this->browseableMedOrders, $this->selectedBrowseOrder);
    }

    public function updatedMedOrdersDate(): void
    {
        $this->selectedBrowseOrderId = null;
        unset($this->browseableMedOrders, $this->selectedBrowseOrder);
    }

    public function updatedMedOrdersSearch(): void
    {
        $this->selectedBrowseOrderId = null;
        unset($this->browseableMedOrders, $this->selectedBrowseOrder);
    }

    public function selectBrowseOrder(int $orderId): void
    {
        $this->selectedBrowseOrderId = $orderId;
        unset($this->selectedBrowseOrder);
    }

    public function clearBrowseOrder(): void
    {
        $this->selectedBrowseOrderId = null;
        unset($this->selectedBrowseOrder);
    }

    /**
     * Copy a prior order into the current visit form (prompt if the form already has lines).
     */
    public function repeatOrder(int $orderId): void
    {
        if ($this->selectedToken === null) {
            Flux::toast(variant: 'danger', text: __('Patient not found.'));

            return;
        }

        $order = MedicationOrder::query()
            ->with(['medicines', 'injections', 'drips.additives'])
            ->find($orderId);

        if ($order === null) {
            Flux::toast(variant: 'danger', text: __('Medication order not found.'));

            return;
        }

        if ($this->orderHasFilledLines()) {
            $this->pendingRepeatOrderId = $order->id;
            $this->showHistoryModal = false;
            $this->showMedOrdersModal = false;
            $this->showOrderPreviewModal = false;
            $this->showRecallModal = false;
            $this->selectedBrowseOrderId = null;
            unset($this->medicationHistory, $this->browseableMedOrders, $this->selectedBrowseOrder);
            $this->showRepeatConflictModal = true;

            return;
        }

        $this->applyRepeatedOrder($order, 'replace');
        $this->finishRepeat();
    }

    /**
     * Confirm append or replace after the conflict prompt.
     */
    public function confirmRepeat(string $mode): void
    {
        if (! in_array($mode, ['append', 'replace'], true)) {
            return;
        }

        if ($this->selectedToken === null || $this->pendingRepeatOrderId === null) {
            Flux::toast(variant: 'danger', text: __('Patient not found.'));
            $this->cancelRepeatConflict();

            return;
        }

        $order = MedicationOrder::query()
            ->with(['medicines', 'injections', 'drips.additives'])
            ->find($this->pendingRepeatOrderId);

        if ($order === null) {
            Flux::toast(variant: 'danger', text: __('Medication order not found.'));
            $this->cancelRepeatConflict();

            return;
        }

        $this->applyRepeatedOrder($order, $mode);
        $this->finishRepeat();
    }

    public function cancelRepeatConflict(): void
    {
        $this->showRepeatConflictModal = false;
        $this->pendingRepeatOrderId = null;
    }

    /**
     * Show medication orders that can be brought back to the doctor's list.
     */
    public function openRecall(): void
    {
        $this->closeModals();
        $this->showRecallModal = true;
        unset($this->recallableOrders);
    }

    public function closeRecall(): void
    {
        $this->showRecallModal = false;
    }

    /**
     * The fulfilled order awaiting a recall strategy.
     */
    #[Computed]
    public function selectedRecallOrder(): ?MedicationOrder
    {
        if ($this->selectedRecallOrderId === null || $this->recentShifts->isEmpty()) {
            return null;
        }

        return MedicationOrder::query()
            ->with(['patient.family', 'queueToken'])
            ->whereKey($this->selectedRecallOrderId)
            ->whereHas('queueToken', fn ($query) => $query->whereIn('status', ['waiting', 'serving', 'served']))
            ->whereHas(
                'queueToken.serviceQueue',
                fn (Builder $query) => $this->whereInRecentShifts($query)
            )
            ->first();
    }

    /**
     * Recall a submitted order for editing on the same token.
     */
    public function recall(int $orderId, RecallMedicationOrder $recallMedicationOrder): void
    {
        $order = $this->recallableOrders->firstWhere('id', $orderId);

        if ($order?->queueToken === null) {
            Flux::toast(variant: 'danger', text: __('Medication order is no longer available to recall.'));

            return;
        }

        if ($order->status === MedicationOrderStatus::Administered) {
            $this->selectedRecallOrderId = $order->id;
            $this->showRecallModal = false;
            $this->showFulfilledRecallOptions = true;
            unset($this->selectedRecallOrder);

            return;
        }

        $this->executeRecall($order, 'clear', $recallMedicationOrder);
    }

    public function recallFulfilled(string $strategy, RecallMedicationOrder $recallMedicationOrder): void
    {
        if (! in_array($strategy, ['clear', 'duplicate', 'reopen'], true)) {
            abort(422);
        }

        $order = $this->selectedRecallOrder;

        if ($order === null) {
            Flux::toast(variant: 'danger', text: __('Medication order is no longer available to recall.'));
            $this->closeFulfilledRecallOptions();

            return;
        }

        $this->executeRecall($order, $strategy, $recallMedicationOrder);
    }

    public function closeFulfilledRecallOptions(): void
    {
        $this->showFulfilledRecallOptions = false;
        $this->selectedRecallOrderId = null;
        unset($this->selectedRecallOrder);
    }

    private function executeRecall(MedicationOrder $order, string $strategy, RecallMedicationOrder $recallMedicationOrder): void
    {
        $actor = auth()->user();

        if ($actor === null || $order->queueToken === null) {
            abort(403);
        }

        try {
            $recallMedicationOrder->handle($order->queueToken, $actor, $strategy);
        } catch (\InvalidArgumentException) {
            Flux::toast(variant: 'danger', text: __('Medication order is no longer available to recall.'));

            return;
        }

        $this->showRecallModal = false;
        $this->closeFulfilledRecallOptions();
        unset($this->queue, $this->selectedToken, $this->recallableOrders);

        Flux::toast(variant: 'success', text: __('Medication order recalled to the doctor list.'));
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
        $this->showMedOrdersModal = false;
        $this->showRepeatConflictModal = false;
        $this->showOrderPreviewModal = false;
        $this->showRecallModal = false;
        $this->showFulfilledRecallOptions = false;
        $this->showDoseModal = false;
        $this->doseDripIndex = null;
        $this->selectedRecallOrderId = null;
        $this->pendingRepeatOrderId = null;
        $this->selectedBrowseOrderId = null;
        unset($this->selectedRecallOrder, $this->browseableMedOrders, $this->selectedBrowseOrder);
        $this->resetOrderPreview();
        $this->notes = '';
        $this->complaintOrDiagnosis = '';
        $this->suggestedPrice = '';
        $this->dripServiceId = null;
        $this->showWrittenMedicationInput = false;
        $this->writtenMedicationName = '';
        $this->medicationLines = [];
        $this->dripLines = [];
        $this->resetValidation();
    }

    /**
     * Turn a price code such as 12z or 12zy into the amount, leaving anything unreadable for validation to flag.
     */
    public function updatedSuggestedPrice(): void
    {
        $amount = PriceShorthand::parse($this->suggestedPrice);

        if ($amount === null) {
            return;
        }

        $this->suggestedPrice = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        $this->resetValidation('suggestedPrice');
    }

    /**
     * Keep an unexpected mode value from breaking the order form.
     */
    public function updatedOrderInputMode(string $value): void
    {
        if (! in_array($value, ['typing', 'visual'], true)) {
            $this->orderInputMode = 'typing';
        }
    }

    /**
     * Add a blank medication row for the Shift+Enter shortcut in typing mode.
     */
    public function addMedicationRowFromShortcut(): void
    {
        if ($this->orderInputMode === 'typing') {
            $this->addMedicationLine();
        }
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

        $this->applyCatalogDefaults((int) explode('.', $key)[0]);
    }

    /**
     * Add or remove a catalog medicine or injection picked from the visual badges.
     */
    public function toggleMedicationSelection(string $selection): void
    {
        if ($this->medicationSelectionId($selection) === null) {
            return;
        }

        foreach ($this->medicationLines as $index => $line) {
            if (($line['selection'] ?? null) === $selection) {
                $this->removeMedicationLine($index);

                return;
            }
        }

        $index = $this->firstBlankMedicationLineIndex();

        if ($index === null) {
            $this->addMedicationLine();
            $index = array_key_last($this->medicationLines);
        }

        $this->medicationLines[$index]['selection'] = $selection;
        $this->applyCatalogDefaults($index);
    }

    public function openWrittenMedicationInput(): void
    {
        $this->showWrittenMedicationInput = true;
        $this->resetValidation('writtenMedicationName');
    }

    /**
     * Add a medicine or injection written by the doctor from visual mode.
     */
    public function addWrittenMedication(): void
    {
        $this->writtenMedicationName = trim($this->writtenMedicationName);

        $this->validateOnly('writtenMedicationName', [
            'writtenMedicationName' => ['required', 'string', 'max:255'],
        ]);

        $isInjection = preg_match('/^inj(?:ection)?(?:[.\s-]|$)/iu', $this->writtenMedicationName) === 1;
        $selection = ($isInjection ? 'custom-injection:' : 'custom:').$this->writtenMedicationName;

        foreach ($this->medicationLines as $line) {
            if (($line['selection'] ?? null) === $selection) {
                $this->showWrittenMedicationInput = false;
                $this->writtenMedicationName = '';

                return;
            }
        }

        $index = $this->firstBlankMedicationLineIndex();

        if ($index === null) {
            $this->addMedicationLine();
            $index = array_key_last($this->medicationLines);
        }

        $this->medicationLines[$index]['selection'] = $selection;
        $this->showWrittenMedicationInput = false;
        $this->writtenMedicationName = '';
    }

    /**
     * Index of the first row without a medication, so badges reuse the blank rows first.
     */
    private function firstBlankMedicationLineIndex(): ?int
    {
        foreach ($this->medicationLines as $index => $line) {
            if (! filled($line['selection'] ?? null)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Copy the catalog dose or administration type onto a row.
     */
    private function applyCatalogDefaults(int $index): void
    {
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
            'dose' => '',
            'additives' => [
                [
                    'injection_id' => null,
                    'dose' => '',
                ],
                [
                    'injection_id' => null,
                    'dose' => '',
                ],
            ],
        ];
    }

    public function removeDripLine(int $index): void
    {
        unset($this->dripLines[$index]);
        $this->dripLines = array_values($this->dripLines);
        $this->closeDoseModal();
    }

    /**
     * Open the dose popup for one drip and its injections, used when a child needs set doses.
     */
    public function openDoseModal(int $dripIndex): void
    {
        if (! filled($this->dripLines[$dripIndex]['drip_base_id'] ?? null)) {
            return;
        }

        $this->doseDripIndex = $dripIndex;
        $this->showDoseModal = true;
    }

    public function closeDoseModal(): void
    {
        $this->showDoseModal = false;
        $this->doseDripIndex = null;
    }

    /**
     * Start a new drip from the typed drip input, from a catalog drip base id or a written name.
     */
    public function addDripFromInput(int|string $dripBase): void
    {
        if (is_numeric($dripBase)) {
            if ($this->dripBases->firstWhere('id', (int) $dripBase) === null) {
                return;
            }

            $selection = (int) $dripBase;
        } else {
            $name = trim($dripBase);

            if ($name === '' || mb_strlen($name) > 255) {
                $this->addError('dripLines', __('Drip name must be 255 characters or fewer.'));

                return;
            }

            $selection = 'custom:'.$name;
        }

        $index = $this->firstBlankDripLineIndex();

        if ($index === null) {
            $this->addDripLine();
            $index = array_key_last($this->dripLines);
        }

        $this->dripLines[$index]['drip_base_id'] = $selection;
        $this->resetValidation('dripLines');
    }

    /**
     * Add a catalog injection id or a written name to the last drip from the typed drip input.
     */
    public function addDripAdditiveFromInput(string $selection): void
    {
        $dripIndex = $this->lastFilledDripLineIndex();

        if ($dripIndex === null) {
            $this->addError('dripLines', __('Choose a drip first, then add injections to it.'));

            return;
        }

        $selection = trim($selection);

        if (is_numeric($selection)) {
            if ($this->injections->firstWhere('id', (int) $selection) === null) {
                return;
            }

            $this->assignDripAdditive($dripIndex, (int) $selection);

            return;
        }

        if ($selection === '' || mb_strlen($selection) > 255) {
            $this->addError('dripLines', __('Injection name must be 255 characters or fewer.'));

            return;
        }

        $this->assignDripAdditive($dripIndex, 'custom:'.$selection);
    }

    /**
     * Remove the last additive, or the last drip when it has none, for Backspace in the typed drip input.
     */
    public function removeLastDripToken(): void
    {
        $dripIndex = $this->lastFilledDripLineIndex();

        if ($dripIndex === null) {
            return;
        }

        $filledAdditives = array_filter(
            $this->dripLines[$dripIndex]['additives'] ?? [],
            fn (array $additive): bool => filled($additive['injection_id'] ?? null)
        );

        if ($filledAdditives === []) {
            $this->removeDripLine($dripIndex);

            return;
        }

        $this->removeDripAdditive($dripIndex, array_key_last($filledAdditives));
    }

    /**
     * Index of the last row with a drip base, which typed additives are added to.
     */
    private function lastFilledDripLineIndex(): ?int
    {
        $filled = array_filter($this->dripLines, fn (array $line): bool => filled($line['drip_base_id'] ?? null));

        return $filled === [] ? null : array_key_last($filled);
    }

    /**
     * Index of the first row without a drip base, so badges reuse the blank rows first.
     */
    private function firstBlankDripLineIndex(): ?int
    {
        foreach ($this->dripLines as $index => $line) {
            if (! filled($line['drip_base_id'] ?? null)) {
                return $index;
            }
        }

        return null;
    }

    public function addDripAdditive(int $dripIndex): void
    {
        if (! isset($this->dripLines[$dripIndex])) {
            return;
        }

        $this->dripLines[$dripIndex]['additives'][] = [
            'injection_id' => null,
            'dose' => '',
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
     * Put an additive on the first blank row, or append a new one.
     */
    private function assignDripAdditive(int $dripIndex, int|string $injectionId): void
    {
        $index = $this->firstBlankDripAdditiveIndex($dripIndex);

        if ($index === null) {
            $this->addDripAdditive($dripIndex);
            $index = array_key_last($this->dripLines[$dripIndex]['additives']);
        }

        $this->dripLines[$dripIndex]['additives'][$index]['injection_id'] = $injectionId;
        $this->dripLines[$dripIndex]['additives'][$index]['dose'] = '';
    }

    /**
     * Index of the first additive row without an injection, so badges reuse blank rows first.
     */
    private function firstBlankDripAdditiveIndex(int $dripIndex): ?int
    {
        foreach ($this->dripLines[$dripIndex]['additives'] ?? [] as $index => $additive) {
            if (! filled($additive['injection_id'] ?? null)) {
                return $index;
            }
        }

        return null;
    }

    private function additiveInjectionId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Display name for a drip additive selection.
     */
    public function dripAdditiveName(mixed $injectionId): string
    {
        if ($this->additiveInjectionId($injectionId) !== null) {
            return $this->injections->firstWhere('id', $this->additiveInjectionId($injectionId))?->name ?? '';
        }

        return $this->customLineName($injectionId);
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
                    $dripBase = $this->resolveDripBase($line['drip_base_id'] ?? null, $dripBasesById);

                    if ($dripBase === null) {
                        return null;
                    }

                    return [
                        'name' => $this->nameWithDose($dripBase['name'], $line['dose'] ?? null),
                        'additives' => collect($line['additives'] ?? [])
                            ->map(function (array $additive) use ($injectionsById): ?array {
                                $resolved = $this->resolveInjection($additive['injection_id'] ?? null, $injectionsById);

                                if ($resolved === null) {
                                    return null;
                                }

                                return [
                                    'name' => $this->nameWithDose($resolved['name'], $additive['dose'] ?? null),
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
        $this->showMedOrdersModal = false;
        $this->showRepeatConflictModal = false;
        $this->showOrderPreviewModal = false;
        $this->showRecallModal = false;
        $this->showFulfilledRecallOptions = false;
        $this->showDoseModal = false;
        $this->doseDripIndex = null;
        $this->selectedRecallOrderId = null;
        $this->pendingRepeatOrderId = null;
        $this->selectedBrowseOrderId = null;
        unset($this->selectedRecallOrder, $this->browseableMedOrders, $this->selectedBrowseOrder);
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

        $existing = $token->medicationOrder;
        $isRecalledServedToken = $token->status === 'served'
            && $existing?->status === MedicationOrderStatus::Draft;

        if (! in_array($token->status, ['waiting', 'serving'], true) && ! $isRecalledServedToken) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer available for medication.'));
            $this->backToList();

            return;
        }

        if (! $token->serviceQueue?->service?->needs_medication) {
            Flux::toast(variant: 'danger', text: __('This service does not require medication.'));
            $this->backToList();

            return;
        }

        if ($advanceQueue && ($token->serviceQueue->status !== 'open' || ! app(TokenDisplayService::class)->followsDoctorToken($token->serviceQueue))) {
            abort(403);
        }

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
                'complaint_or_diagnosis' => filled($validated['complaintOrDiagnosis'] ?? null)
                    ? $validated['complaintOrDiagnosis']
                    : null,
                'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
                'administered_by' => null,
                'administered_at' => null,
            ]);
            $order->save();

            $order->symptoms()->sync([]);

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
                $dripBase = $this->resolveDripBase($line['drip_base_id'] ?? null, $dripBasesById);

                if ($dripBase === null) {
                    continue;
                }

                $drip = $order->drips()->create([
                    ...$dripBase,
                    'dose' => $this->cleanDose($line['dose'] ?? null),
                ]);

                foreach ($line['additives'] ?? [] as $additive) {
                    $resolved = $this->resolveInjection($additive['injection_id'] ?? null, $injectionsById);

                    if ($resolved === null) {
                        continue;
                    }

                    $drip->additives()->create([
                        'injection_id' => $resolved['injection_id'],
                        'name' => $resolved['name'],
                        'dose' => $this->cleanDose($additive['dose'] ?? null),
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
        $this->updatedSuggestedPrice();

        $validated = $this->validate($this->orderRules(), [
            'suggestedPrice.numeric' => __('Enter an amount like 1200, or a code like 12z or 12zy.'),
        ]);

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
            ->whereIn(
                'id',
                $dripLines->pluck('drip_base_id')
                    ->filter(fn (mixed $id): bool => is_numeric($id))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all()
            )
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
     * Orders with drips always get a reception charge, even when the doctor
     * leaves the suggested price blank for reception to fill in.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncDripCharge(MedicationOrder $order, QueueToken $token, array $validated): void
    {
        $pendingCharge = DripCharge::query()
            ->where('queue_token_id', $token->id)
            ->where('status', DripChargeStatus::Pending)
            ->first();

        if (! $order->drips()->exists()) {
            $pendingCharge?->delete();

            return;
        }

        $service = Service::query()
            ->active()
            ->where('is_drip', true)
            ->find($validated['dripServiceId'] ?? null);

        if ($service === null) {
            $service = Service::query()
                ->active()
                ->where('is_drip', true)
                ->orderBy('name')
                ->first();
        }

        if ($service === null) {
            return;
        }

        $share = app(ResolveDripShareDoctor::class)->resolve($service, auth()->user());
        $suggestedPrice = filled($validated['suggestedPrice'] ?? null)
            ? $validated['suggestedPrice']
            : null;

        $attributes = [
            'patient_id' => $token->patient_id,
            'queue_token_id' => $token->id,
            'medication_order_id' => $order->id,
            'service_id' => $service->id,
            'doctor_id' => $share['doctor']?->id,
            'suggested_price' => $suggestedPrice,
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
            'complaintOrDiagnosis' => ['required', 'string', 'max:2000'],
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
            'dripLines.*.drip_base_id' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! filled($value)) {
                    return;
                }

                if (is_numeric($value)) {
                    if (! DripBase::query()->whereKey((int) $value)->exists()) {
                        $fail(__('The selected drip is invalid.'));
                    }

                    return;
                }

                $name = $this->customLineName($value);

                if ($name === '' || mb_strlen($name) > 255) {
                    $fail(__('Drip name must be 255 characters or fewer.'));
                }
            }],
            'dripLines.*.additives' => ['array'],
            'dripLines.*.additives.*.injection_id' => ['nullable', $this->injectionSelectionRule()],
            'dripLines.*.dose' => ['nullable', 'string', 'max:50'],
            'dripLines.*.additives.*.dose' => ['nullable', 'string', 'max:50'],
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
     * A trimmed dose, or null when the doctor left it blank.
     */
    private function cleanDose(mixed $dose): ?string
    {
        $dose = is_string($dose) ? trim($dose) : '';

        return $dose === '' ? null : $dose;
    }

    /**
     * A drip or injection name followed by its dose, when one was given.
     */
    private function nameWithDose(string $name, mixed $dose): string
    {
        $dose = $this->cleanDose($dose);

        return $dose === null ? $name : $name.' — '.$dose;
    }

    /**
     * Turn a drip selection into a catalog drip base id and display name.
     *
     * @param  Collection<int, DripBase>  $dripBasesById
     * @return array{drip_base_id: int|null, name: string}|null
     */
    private function resolveDripBase(mixed $raw, Collection $dripBasesById): ?array
    {
        if (is_numeric($raw)) {
            $dripBase = $dripBasesById->get((int) $raw);

            return $dripBase === null ? null : [
                'drip_base_id' => $dripBase->id,
                'name' => $dripBase->name,
            ];
        }

        $name = $this->customLineName($raw);

        return $name === '' ? null : [
            'drip_base_id' => null,
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

        $this->suggestedPrice = $pendingCharge?->suggested_price !== null
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
        $mapped = $this->mapOrderToFormLines($order);
        $this->medicationLines = $mapped['medicationLines'];
        $this->dripLines = $mapped['dripLines'];
        $this->ensureDefaultOrderLines();
    }

    /**
     * Whether the current form already has any medicine, injection, or drip selected.
     */
    private function orderHasFilledLines(): bool
    {
        foreach ($this->medicationLines as $line) {
            if (filled($line['selection'] ?? null)) {
                return true;
            }
        }

        foreach ($this->dripLines as $line) {
            if (filled($line['drip_base_id'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map a persisted order into the Livewire form line shape.
     *
     * @return array{
     *     medicationLines: list<array{selection: string|null, dose: string, administration_type: string, comment: string}>,
     *     dripLines: list<array{drip_base_id: int|string|null, dose: string, additives: list<array{injection_id: int|string|null, dose: string}>}>
     * }
     */
    private function mapOrderToFormLines(MedicationOrder $order): array
    {
        $order->loadMissing(['medicines', 'injections', 'drips.additives']);

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

        $medicationLines = collect($medicineLines->all())
            ->merge($injectionLines->all())
            ->values()
            ->all();

        $dripLines = $order->drips
            ->map(fn ($drip) => [
                'drip_base_id' => $drip->drip_base_id ?? 'custom:'.$drip->name,
                'dose' => $drip->dose ?? '',
                'additives' => $drip->additives->map(fn ($additive) => [
                    'injection_id' => $additive->injection_id ?? 'custom:'.$additive->name,
                    'dose' => $additive->dose ?? '',
                ])->values()->all(),
            ])->values()->all();

        return [
            'medicationLines' => $medicationLines,
            'dripLines' => $dripLines,
        ];
    }

    /**
     * Apply a prior order onto the current form.
     *
     * @param  'append'|'replace'  $mode
     */
    private function applyRepeatedOrder(MedicationOrder $order, string $mode): void
    {
        $mapped = $this->mapOrderToFormLines($order);

        if ($mode === 'replace') {
            $this->medicationLines = $mapped['medicationLines'];
            $this->dripLines = $mapped['dripLines'];
            $this->complaintOrDiagnosis = $order->complaint_or_diagnosis ?? '';
            $this->notes = $order->notes ?? '';
        } else {
            $existingMedications = collect($this->medicationLines)
                ->filter(fn (array $line): bool => filled($line['selection'] ?? null))
                ->values()
                ->all();
            $existingDrips = collect($this->dripLines)
                ->filter(fn (array $line): bool => filled($line['drip_base_id'] ?? null))
                ->map(function (array $line): array {
                    $additives = collect($line['additives'] ?? [])
                        ->filter(fn (array $additive): bool => filled($additive['injection_id'] ?? null))
                        ->values()
                        ->all();

                    return [
                        'drip_base_id' => $line['drip_base_id'],
                        'dose' => $line['dose'] ?? '',
                        'additives' => $additives,
                    ];
                })
                ->values()
                ->all();

            $this->medicationLines = array_values(array_merge($existingMedications, $mapped['medicationLines']));
            $this->dripLines = array_values(array_merge($existingDrips, $mapped['dripLines']));

            if (blank($this->complaintOrDiagnosis) && filled($order->complaint_or_diagnosis)) {
                $this->complaintOrDiagnosis = $order->complaint_or_diagnosis;
            }

            if (blank($this->notes) && filled($order->notes)) {
                $this->notes = $order->notes;
            }
        }

        $this->ensureDefaultOrderLines();
    }

    private function finishRepeat(): void
    {
        $this->showHistoryModal = false;
        $this->showMedOrdersModal = false;
        $this->showRepeatConflictModal = false;
        $this->pendingRepeatOrderId = null;
        $this->selectedBrowseOrderId = null;
        unset($this->medicationHistory, $this->browseableMedOrders, $this->selectedBrowseOrder);

        Flux::toast(variant: 'success', text: __('Previous medications added to this visit.'));
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

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Medication') }}</flux:heading>
        @if ($selectedTokenId === null)
            <flux:badge color="zinc" size="lg">{{ $this->queue->count() }}</flux:badge>
        @else
            <flux:radio.group wire:model.live="orderInputMode" variant="segmented" size="sm">
                <flux:radio value="typing" icon="pencil-square">{{ __('Typing') }}</flux:radio>
                <flux:radio value="visual" icon="squares-2x2">{{ __('Visual') }}</flux:radio>
            </flux:radio.group>
        @endif
    </div>

    @if ($selectedTokenId === null)
        <div class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->queue as $token)
                <div wire:key="medication-token-{{ $token->id }}" class="relative">
                <x-paper-slip
                    as="button"
                    type="button"
                    :token="$token->token_number"
                    wire:click="selectToken({{ $token->id }})"
                    class="min-h-48 active:scale-[0.99] hover:-translate-y-0.5"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <x-patient-phone-indicator :patient="$token->patient" />
                            <p class="truncate text-lg font-semibold text-zinc-900">
                                {{ $token->patient?->name ?? __('Unknown') }}
                            </p>
                        </div>
                    </div>
                    <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                        {{ $token->patient?->mrn ?? __('No MRN') }}
                        · {{ $token->serviceQueue?->service?->name }}
                    </p>
                    @if ($this->isFromPreviousShift($token))
                        <p class="w-fit rounded-sm bg-amber-200/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">
                            {{ __('Previous shift') }}
                        </p>
                    @endif
                    @if ($token->medicationOrder)
                        <div class="mt-1 border-t border-dashed border-zinc-400/70 pt-2 text-xs text-zinc-600">
                            {{ $token->medicationOrder->status->label() }}
                        </div>
                    @endif
                    <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                        {{ __('Tap to prescribe') }}
                    </p>
                </x-paper-slip>
                @if ($token->medicationOrder === null)
                    <button
                        type="button"
                        class="absolute end-2 top-4 z-10 flex size-8 cursor-pointer items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-900/10 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-zinc-900"
                        aria-label="{{ __('Dismiss :name', ['name' => $token->patient?->name ?? __('patient')]) }}"
                        title="{{ __('Dismiss') }}"
                        wire:click="dismissFromQueue({{ $token->id }})"
                    >
                        <flux:icon name="x-mark" variant="mini" class="size-5" />
                    </button>
                @endif
                </div>
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
        <div class="sticky top-0 z-10 -mx-4 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900 sm:mx-0 sm:rounded-xl sm:border">
            <div class="flex items-center gap-3">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-lg font-bold text-white dark:bg-white dark:text-zinc-900">
                    {{ $token?->token_number }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="flex min-w-0 items-center gap-2 truncate text-lg font-semibold text-zinc-900 dark:text-white">
                        <x-patient-phone-indicator :patient="$token?->patient" />
                        <span class="truncate">{{ $token?->patient?->name ?? __('Unknown') }}</span>
                        @if (filled($complaintOrDiagnosis))
                            <flux:badge size="sm" color="sky" class="ms-1 align-middle">
                                {{ __('Diagnosis') }}: {{ $complaintOrDiagnosis }}
                            </flux:badge>
                        @endif
                    </p>
                    <p class="truncate text-sm text-zinc-500">
                        {{ $token?->patient?->mrn ?? __('No MRN') }}
                        · {{ $token?->serviceQueue?->service?->name }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <flux:button type="button" size="sm" variant="ghost" icon="clipboard-document-list" wire:click="openMedOrders">
                        {{ __('Med Orders') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="ghost" icon="clock" wire:click="openHistory">
                        {{ __('History') }}
                    </flux:button>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mb-3 flex items-center justify-between gap-2">
                <flux:heading size="sm">{{ __('Vitals') }}</flux:heading>
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
                                    <p class="mt-1 font-mono text-lg font-bold text-zinc-900">{{ $vital->temperature !== null ? $vital->temperature.'°F' : '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('BP') }}</p>
                                    <p class="mt-1 font-mono text-lg font-bold text-zinc-900">{{ ($vital->bp_systolic !== null || $vital->bp_diastolic !== null) ? ($vital->bp_systolic ?? '—').'/'.($vital->bp_diastolic ?? '—') : '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('BSR') }}</p>
                                    <p class="mt-1 font-mono text-lg font-bold text-zinc-900">{{ $vital->bsr ?? '—' }}</p>
                                </div>
                            </div>
                        </x-paper-slip>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-zinc-500">{{ __('No vitals recorded for this visit.') }}</p>
            @endif
        </div>

        <flux:field>
            <flux:label>{{ __('Diagnosis') }}</flux:label>
            <flux:input
                wire:model.live="complaintOrDiagnosis"
                type="text"
                placeholder="{{ __('Enter diagnosis') }}"
                required
            />
            <flux:error name="complaintOrDiagnosis" />
        </flux:field>

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
            @keydown.shift.enter.prevent="$wire.addMedicationRowFromShortcut()"
            @keydown.alt.arrow-up.prevent="navigate('up')"
            @keydown.alt.arrow-down.prevent="navigate('down')"
            @keydown.alt.arrow-left.prevent="navigate('left')"
            @keydown.alt.arrow-right.prevent="navigate('right')"
        >
            @php($filledDrips = array_filter($dripLines, fn (array $line): bool => filled($line['drip_base_id'] ?? null)))
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-3" data-nav-row>
                    <flux:heading size="sm">{{ __('Drips') }}</flux:heading>
                    @if ($this->dripServices->isNotEmpty())
                        @if ($this->dripServices->count() > 1)
                            <div class="w-44" data-nav-field>
                                <flux:select wire:model="dripServiceId" size="sm" aria-label="{{ __('Drip service') }}">
                                    <option value="">{{ __('Select drip service') }}</option>
                                    @foreach ($this->dripServices as $dripService)
                                        <option value="{{ $dripService->id }}">{{ $dripService->name }}</option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif
                        <div class="w-36" data-nav-field>
                            <flux:input
                                wire:model.live.blur="suggestedPrice"
                                type="text"
                                inputmode="text"
                                autocomplete="off"
                                size="sm"
                                aria-label="{{ __('Drip charge') }}"
                                placeholder="{{ __('Drip charge') }}"
                                title="{{ __('z = 100, y = 50. 12z = 1200, 12zy = 1250') }}"
                            />
                        </div>
                    @endif
                </div>
                <flux:error name="dripServiceId" />
                <flux:error name="suggestedPrice" />
            </div>
                <div class="space-y-2" data-nav-row>
                    <div
                        data-nav-field
                        x-data="{
                            search: '',
                            open: false,
                            highlight: 0,
                            pending: Promise.resolve(),
                            pendingDrip: false,
                            startingNew: false,
                            drips: {{ \Illuminate\Support\Js::from($this->dripBaseOptions) }},
                            additives: {{ \Illuminate\Support\Js::from($this->injectionOptions) }},
                            get hasDrip() {
                                return this.pendingDrip || this.$refs.input?.dataset.hasDrip === '1';
                            },
                            get wantsDrip() {
                                return ! this.hasDrip || this.startingNew;
                            },
                            clean(text) {
                                return String(text).toLowerCase().replace(/\binj(ection)?[.,]?\s+/g, '');
                            },
                            rank(option, query) {
                                const label = this.clean(option.label);
                                const haystack = label + ' ' + this.clean(option.keywords ?? '');

                                if (label.startsWith(query)) {
                                    return 0;
                                }

                                if (haystack.split(/[\s\/—-]+/).some((word) => word.startsWith(query))) {
                                    return 1;
                                }

                                return haystack.includes(query) ? 2 : null;
                            },
                            matches(options, kind) {
                                const query = this.clean(this.search.trim());

                                return options
                                    .map((option) => ({ ...option, kind, score: query === '' ? 0 : this.rank(option, query) }))
                                    .filter((option) => option.score !== null)
                                    .sort((a, b) => a.score - b.score);
                            },
                            get suggestions() {
                                const drips = this.matches(this.drips, 'drip');

                                if (this.wantsDrip) {
                                    return drips.slice(0, 8);
                                }

                                const additives = this.search.trim() === '' ? [] : this.matches(this.additives, 'additive');

                                return [...additives, ...drips].slice(0, 8);
                            },
                            get canWrite() {
                                const query = this.search.trim();

                                const known = this.wantsDrip ? this.drips : [...this.additives, ...this.drips];

                                return query !== ''
                                    && ! known.some((option) => this.clean(option.label) === this.clean(query));
                            },
                            get items() {
                                return this.canWrite
                                    ? [...this.suggestions, { kind: this.wantsDrip ? 'custom-drip' : 'custom', value: this.search.trim(), label: this.search.trim() }]
                                    : this.suggestions;
                            },
                            onType() {
                                this.open = true;
                                this.highlight = 0;
                            },
                            move(delta) {
                                const length = this.items.length;

                                if (length === 0) {
                                    return;
                                }

                                this.open = true;
                                this.highlight = (this.highlight + delta + length) % length;
                            },
                            send(call) {
                                this.pending = this.pending
                                    .then(call)
                                    .catch(() => {})
                                    .finally(() => this.$nextTick(() => this.$refs.input?.focus()));
                            },
                            choose(item) {
                                if (! item) {
                                    return;
                                }

                                this.search = '';
                                this.highlight = 0;
                                this.open = false;

                                this.startingNew = false;

                                if (item.kind === 'drip' || item.kind === 'custom-drip') {
                                    this.pendingDrip = true;
                                    this.send(() => $wire.addDripFromInput(item.value).finally(() => this.pendingDrip = false));

                                    return;
                                }

                                this.send(() => $wire.addDripAdditiveFromInput(String(item.value)));
                            },
                            commit() {
                                if (this.search.trim() === '') {
                                    this.startingNew = this.hasDrip;

                                    return;
                                }

                                this.choose(this.items[Math.min(this.highlight, this.items.length - 1)]);
                            },
                            removeLast() {
                                if (this.search !== '') {
                                    return;
                                }

                                if (this.startingNew) {
                                    this.startingNew = false;

                                    return;
                                }

                                this.send(() => $wire.removeLastDripToken());
                            },
                        }"
                        @click.outside="open = false"
                        class="relative"
                    >
                        <div
                            class="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-zinc-200 bg-white px-2 py-2 shadow-xs focus-within:ring-2 focus-within:ring-accent dark:border-white/10 dark:bg-white/10"
                            @click="$refs.input.focus()"
                        >
                            @foreach ($filledDrips as $dripIndex => $drip)
                                <div wire:key="drip-token-{{ $dripIndex }}" class="flex flex-wrap items-center gap-1 {{ $loop->first ? '' : 'border-s border-zinc-200 ps-3 dark:border-zinc-600' }}">
                                    <flux:badge size="sm" color="teal" icon="droplets">
                                        <button type="button" class="cursor-pointer" title="{{ __('Set dose') }}" @click.stop="open = false" wire:click="openDoseModal({{ $dripIndex }})">
                                            {{ $this->dripLineNames[$dripIndex] ?? '' }}@if (filled($drip['dose'] ?? null)) <span class="font-semibold">· {{ trim($drip['dose']) }}</span>@endif
                                        </button>
                                        <flux:badge.close wire:click="removeDripLine({{ $dripIndex }})" aria-label="{{ __('Remove drip') }}" />
                                    </flux:badge>
                                    @foreach (array_filter($drip['additives'] ?? [], fn (array $additive): bool => filled($additive['injection_id'] ?? null)) as $additiveIndex => $additive)
                                        <span wire:key="drip-token-{{ $dripIndex }}-additive-{{ $additiveIndex }}" class="flex items-center gap-1">
                                            <span class="text-sm text-zinc-400" aria-hidden="true">+</span>
                                            <flux:badge size="sm" color="blue">
                                                <button type="button" class="cursor-pointer" title="{{ __('Set dose') }}" @click.stop="open = false" wire:click="openDoseModal({{ $dripIndex }})">
                                                    {{ $this->dripAdditiveName($additive['injection_id']) }}@if (filled($additive['dose'] ?? null)) <span class="font-semibold">· {{ trim($additive['dose']) }}</span>@endif
                                                </button>
                                                <flux:badge.close wire:click="removeDripAdditive({{ $dripIndex }}, {{ $additiveIndex }})" aria-label="{{ __('Remove additive') }}" />
                                            </flux:badge>
                                        </span>
                                    @endforeach
                                    @if ($loop->last)
                                        <span x-show="! startingNew" class="text-sm text-zinc-400" aria-hidden="true">+</span>
                                    @endif
                                </div>
                            @endforeach
                            <span x-show="startingNew" x-cloak class="border-s border-zinc-200 ps-3 text-xs font-medium uppercase tracking-wide text-teal-600 dark:border-zinc-600 dark:text-teal-400">{{ __('New drip') }}</span>
                            <input
                                x-ref="input"
                                data-has-drip="{{ $filledDrips === [] ? '0' : '1' }}"
                                x-model="search"
                                type="text"
                                role="combobox"
                                aria-autocomplete="list"
                                aria-controls="drip-composer-list"
                                :aria-expanded="open && items.length > 0"
                                aria-label="{{ __('Drips') }}"
                                autocomplete="off"
                                placeholder="{{ $filledDrips === [] ? __('Type a drip, e.g. Provas') : __('Add an injection, or press Enter again for a new drip') }}"
                                data-placeholder="{{ $filledDrips === [] ? __('Type a drip, e.g. Provas') : __('Add an injection, or press Enter again for a new drip') }}"
                                :placeholder="startingNew ? {{ \Illuminate\Support\Js::from(__('Type the new drip')) }} : $el.dataset.placeholder"
                                @input="onType()"
                                @focus="open = search.trim() !== ''"
                                @keydown.arrow-down.prevent="if (! $event.altKey) move(1)"
                                @keydown.arrow-up.prevent="if (! $event.altKey) move(-1)"
                                @keydown.enter.prevent.stop="commit()"
                                @keydown.tab="if (open && search.trim() !== '') { $event.preventDefault(); commit() }"
                                @keydown.backspace="removeLast()"
                                @keydown.escape="if (open) { $event.stopPropagation(); open = false } else if (startingNew) { $event.stopPropagation(); startingNew = false }"
                                class="h-8 min-w-40 flex-1 border-0 bg-transparent px-1 text-sm text-zinc-700 outline-none placeholder:text-zinc-400 dark:text-zinc-200 dark:placeholder:text-zinc-500"
                            >
                        </div>

                        <div
                            x-show="open && items.length > 0"
                            x-cloak
                            class="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <ul id="drip-composer-list" wire:ignore class="max-h-72 overflow-y-auto py-1" role="listbox">
                                <template x-for="(item, index) in items" :key="item.kind + ':' + item.value">
                                    <li role="option" :aria-selected="index === highlight">
                                        <button
                                            type="button"
                                            tabindex="-1"
                                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm text-zinc-700 dark:text-zinc-200"
                                            :class="index === highlight ? 'bg-zinc-100 dark:bg-white/10' : 'hover:bg-zinc-50 dark:hover:bg-white/5'"
                                            @mouseenter="highlight = index"
                                            @mousedown.prevent
                                            @click="choose(item)"
                                        >
                                            <span x-text="item.kind.startsWith('custom') ? {{ \Illuminate\Support\Js::from(__('Write')) }} + ' “' + item.label + '”' : item.label"></span>
                                            <span
                                                class="shrink-0 text-xs"
                                                :class="item.kind === 'drip' || item.kind === 'custom-drip' ? 'text-teal-600 dark:text-teal-400' : 'text-zinc-400'"
                                                x-text="item.kind === 'drip' || item.kind === 'custom-drip' ? {{ \Illuminate\Support\Js::from(__('New drip')) }} : {{ \Illuminate\Support\Js::from(__('Add to drip')) }}"
                                            ></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>
                    <flux:error name="dripLines" />
                </div>

            <flux:heading size="sm">{{ __('Medications') }}</flux:heading>

            @if ($orderInputMode === 'visual')
                @php($selectedMedications = collect($medicationLines)->pluck('selection')->filter()->all())
                <div class="space-y-3">
                    @foreach ([
                        ['label' => __('Medicines'), 'prefix' => 'medicine', 'items' => $this->medicines, 'idleColor' => 'violet', 'selectedColor' => 'green', 'icon' => 'pill'],
                        ['label' => __('Injections'), 'prefix' => 'injection', 'items' => $this->injections, 'idleColor' => 'blue', 'selectedColor' => 'sky', 'icon' => 'syringe'],
                    ] as $group)
                        <div wire:key="visual-group-{{ $group['prefix'] }}" class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500">
                                <flux:icon :name="$group['icon']" variant="mini" class="size-3.5" />
                                {{ $group['label'] }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($group['prefix'] === 'medicine' ? $group['items']->sortBy(fn ($item) => [$item->isSyrup() ? 1 : 0, $item->catalogLabel()])->values() : $group['items'] as $item)
                                    @php($value = $group['prefix'].':'.$item->id)
                                    @php($isSelected = in_array($value, $selectedMedications, true))
                                    @php($isSyrup = $group['prefix'] === 'medicine' && $item->isSyrup())
                                    <flux:badge
                                        as="button"
                                        type="button"
                                        size="lg"
                                        :color="$isSelected ? $group['selectedColor'] : ($isSyrup ? 'amber' : $group['idleColor'])"
                                        :icon="$isSelected ? 'check' : ($isSyrup ? 'beaker' : $group['icon'])"
                                        class="cursor-pointer"
                                        wire:key="visual-{{ $group['prefix'] }}-{{ $item->id }}"
                                        wire:click="toggleMedicationSelection('{{ $value }}')"
                                    >
                                        @if ($group['prefix'] === 'medicine')
                                            {{ $item->catalogLabel() }}
                                        @else
                                            {{ $item->name }}
                                        @endif
                                    </flux:badge>
                                @empty
                                    <p class="text-sm text-zinc-500">{{ __('Nothing in this catalog yet.') }}</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach

                    <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Selected medications') }}</p>
                            <flux:badge
                                as="button"
                                type="button"
                                size="lg"
                                icon="plus"
                                class="cursor-pointer"
                                aria-label="{{ __('Write medication') }}"
                                wire:click="openWrittenMedicationInput"
                            >
                                {{ __('Add') }}
                            </flux:badge>
                        </div>
                        @if ($showWrittenMedicationInput)
                            <div
                                class="flex flex-col gap-2 sm:flex-row"
                                x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                            >
                                <div class="flex-1">
                                    <flux:input
                                        wire:model="writtenMedicationName"
                                        wire:keydown.enter.prevent="addWrittenMedication"
                                        type="text"
                                        maxlength="255"
                                        placeholder="{{ __('Type Tab ... or Inj ...') }}"
                                    />
                                    <flux:error name="writtenMedicationName" />
                                </div>
                                <flux:button type="button" variant="primary" wire:click="addWrittenMedication">
                                    {{ __('Select') }}
                                </flux:button>
                            </div>
                        @endif
                        @forelse (array_filter($medicationLines, fn (array $line): bool => filled($line['selection'] ?? null)) as $index => $line)
                            @php($isInjection = str_starts_with($line['selection'] ?? '', 'injection:') || str_starts_with($line['selection'] ?? '', 'custom-injection:'))
                            <div wire:key="visual-line-{{ $index }}" data-nav-row class="grid gap-2 sm:grid-cols-12">
                                <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100 sm:col-span-5 sm:self-center">
                                    {{ $this->medicationLineNames[$index] ?? '' }}
                                </p>
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
                                <div class="flex items-start sm:col-span-1">
                                    <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeMedicationLine({{ $index }})" />
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('Tap a medicine or injection above to add it.') }}</p>
                        @endforelse
                    </div>
                    <flux:error name="medicationLines" />
                </div>
            @else
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

    @if ($selectedTokenId === null)
        <div class="fixed end-6 bottom-24 z-20">
            <flux:tooltip :content="__('Recall medication order')" position="left">
                <flux:button
                    type="button"
                    variant="primary"
                    icon="arrow-uturn-left"
                    aria-label="{{ __('Recall medication order') }}"
                    wire:click="openRecall"
                    class="size-14 rounded-full shadow-xl"
                />
            </flux:tooltip>
        </div>
    @endif

    <flux:modal name="medication-recall" wire:model="showRecallModal" class="w-full max-w-xl">
        <div class="space-y-4">
            <div>
                <flux:heading level="2">{{ __('Recall medication order') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Select a patient to return the same token to your medication list.') }}</flux:text>
            </div>

            <div class="max-h-[70vh] space-y-3 overflow-y-auto pe-1">
                @forelse ($this->recallableOrders as $order)
                    <x-paper-slip
                        as="button"
                        type="button"
                        :token="$order->queueToken?->token_number"
                        wire:key="recall-order-{{ $order->id }}"
                        wire:click="recall({{ $order->id }})"
                        class="w-full text-left active:scale-[0.99] hover:-translate-y-0.5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex min-w-0 items-center gap-2 truncate text-base font-semibold text-zinc-900">
                                    <x-patient-phone-indicator :patient="$order->patient" />
                                    <span class="truncate">{{ $order->patient?->name ?? __('Unknown') }}</span>
                                </p>
                                <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                                    {{ $order->patient?->mrn ?? __('No MRN') }}
                                    · {{ $order->queueToken?->serviceQueue?->service?->name }}
                                </p>
                            </div>
                            <flux:badge size="sm" color="{{ $order->status === MedicationOrderStatus::Administered ? 'green' : 'zinc' }}">
                                {{ $order->status->label() }}
                            </flux:badge>
                        </div>
                        <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                            {{ __('Tap to recall') }}
                        </p>
                    </x-paper-slip>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-600">
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('No medication orders to recall') }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Orders from the current shift will appear here after they are submitted.') }}</p>
                    </div>
                @endforelse
            </div>

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeRecall">
                    {{ __('Close') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="fulfilled-medication-recall" wire:model="showFulfilledRecallOptions" class="w-full max-w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading level="2">{{ __('Recall fulfilled order') }}</flux:heading>
                <flux:text class="mt-1 flex items-center gap-2">
                    <x-patient-phone-indicator :patient="$this->selectedRecallOrder?->patient" />
                    <span>
                        {{ $this->selectedRecallOrder?->patient?->name ?? __('Patient') }}
                        · {{ __('Token :token', ['token' => $this->selectedRecallOrder?->queueToken?->token_number ?? '?']) }}
                    </span>
                </flux:text>
            </div>

            <div class="grid gap-3">
                <flux:button type="button" variant="ghost" wire:click="recallFulfilled('clear')" class="h-auto justify-start py-3 text-left">
                    <span>
                        <span class="block font-semibold">{{ __('Clear slate') }}</span>
                        <span class="block text-xs font-normal text-zinc-500">{{ __('Create a new empty medication order on this token.') }}</span>
                    </span>
                </flux:button>

                <flux:button type="button" variant="primary" wire:click="recallFulfilled('duplicate')" class="h-auto justify-start py-3 text-left">
                    <span>
                        <span class="block font-semibold">{{ __('Duplicate order') }}</span>
                        <span class="block text-xs font-normal opacity-80">{{ __('Create a new editable order with the same diagnosis, notes, medicines, injections, and drips.') }}</span>
                    </span>
                </flux:button>

                <flux:button type="button" variant="danger" wire:click="recallFulfilled('reopen')" class="h-auto justify-start py-3 text-left">
                    <span>
                        <span class="block font-semibold">{{ __('Edit fulfilled order') }}</span>
                        <span class="block text-xs font-normal opacity-80">{{ __('Reopen the original order and clear all fulfilled delivery marks so it must be delivered again.') }}</span>
                    </span>
                </flux:button>
            </div>

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeFulfilledRecallOptions">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="drip-dose" wire:model="showDoseModal" @close="$wire.closeDoseModal()" class="w-full max-w-md">
        @php($doseDrip = $doseDripIndex !== null ? ($dripLines[$doseDripIndex] ?? null) : null)
        <div class="space-y-4">
            <div>
                <flux:heading level="2">{{ __('Dose') }}</flux:heading>
                <flux:text class="mt-1">{{ __('For children. Leave blank to give the usual dose.') }}</flux:text>
            </div>

            @if ($doseDrip !== null)
                <div class="space-y-3">
                    <div class="grid grid-cols-5 items-center gap-3">
                        <p class="col-span-3 truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                            {{ $this->dripLineNames[$doseDripIndex] ?? '' }}
                        </p>
                        <div class="col-span-2">
                            <flux:input
                                wire:model="dripLines.{{ $doseDripIndex }}.dose"
                                size="sm"
                                maxlength="50"
                                placeholder="{{ __('e.g. 330ml') }}"
                                aria-label="{{ __('Dose for :name', ['name' => $this->dripLineNames[$doseDripIndex] ?? '']) }}"
                            />
                        </div>
                    </div>
                    @foreach (array_filter($doseDrip['additives'] ?? [], fn (array $additive): bool => filled($additive['injection_id'] ?? null)) as $additiveIndex => $additive)
                        <div wire:key="dose-additive-{{ $doseDripIndex }}-{{ $additiveIndex }}" class="grid grid-cols-5 items-center gap-3">
                            <p class="col-span-3 truncate text-sm text-zinc-700 dark:text-zinc-200">
                                + {{ $this->dripAdditiveName($additive['injection_id']) }}
                            </p>
                            <div class="col-span-2">
                                <flux:input
                                    wire:model="dripLines.{{ $doseDripIndex }}.additives.{{ $additiveIndex }}.dose"
                                    size="sm"
                                    maxlength="50"
                                    placeholder="{{ __('e.g. 550mg') }}"
                                    aria-label="{{ __('Dose for :name', ['name' => $this->dripAdditiveName($additive['injection_id'])]) }}"
                                />
                            </div>
                        </div>
                    @endforeach
                    <flux:error name="dripLines.{{ $doseDripIndex }}.dose" />
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button type="button" variant="primary" wire:click="closeDoseModal">{{ __('Done') }}</flux:button>
            </div>
        </div>
    </flux:modal>

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
                    <p class="flex min-w-0 items-center gap-2 truncate text-lg font-semibold text-zinc-900">
                        <x-patient-phone-indicator :patient="$this->selectedToken?->patient" />
                        <span class="truncate">{{ $this->selectedToken?->patient?->name ?? __('Unknown') }}</span>
                    </p>
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
                                <div
                                    wire:key="preview-drip-{{ $index }}"
                                    @class(['mb-2', 'border-t border-dashed border-zinc-400/70 pt-2' => $index > 0])
                                >
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
            <p class="flex items-center gap-2 text-sm text-zinc-500">
                <x-patient-phone-indicator :patient="$this->selectedToken?->patient" />
                <span>
                    {{ $this->selectedToken?->patient?->name ?? __('Unknown') }}
                    · {{ $this->selectedToken?->patient?->mrn ?? __('No MRN') }}
                </span>
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
                            <div class="flex flex-wrap items-center gap-2">
                                @if (filled($order->complaint_or_diagnosis))
                                    <flux:badge size="sm" color="sky">
                                        {{ __('Diagnosis') }}: {{ $order->complaint_or_diagnosis }}
                                    </flux:badge>
                                @endif
                                <flux:badge size="sm" color="{{ $order->status === \App\Enums\MedicationOrderStatus::Administered ? 'green' : 'zinc' }}">
                                    {{ $order->status->label() }}
                                </flux:badge>
                                <flux:button type="button" size="sm" variant="primary" wire:click="repeatOrder({{ $order->id }})">
                                    {{ __('Repeat') }}
                                </flux:button>
                            </div>
                        </div>

                        @if ($order->medicines->isNotEmpty())
                            <div class="mb-2">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Medicines') }}</p>
                                @foreach ($order->sortedMedicines() as $medicine)
                                    <x-medicine-line
                                        :name="$medicine->name"
                                        :detail="collect([$medicine->dose->label(), $medicine->comment])->filter()->implode(' · ')"
                                        :is-syrup="$medicine->isSyrup()"
                                        class="!bg-transparent !px-0 !py-0 !ring-0 dark:!text-zinc-200"
                                    />
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
                                        {{ $drip->displayName() }}
                                    </p>
                                    @foreach ($drip->additives as $additive)
                                        <p class="ms-3 text-sm text-zinc-500">
                                            + {{ $additive->displayName() }}
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

    <flux:modal name="med-orders-browse" wire:model="showMedOrdersModal" class="w-full max-w-2xl">
        <div class="space-y-4">
            <flux:heading level="2">{{ __('Med Orders') }}</flux:heading>
            <p class="text-sm text-zinc-500">
                {{ __('Find a previous visit by date and copy its medications to this patient.') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input type="date" wire:model.live="medOrdersDate" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Search') }}</flux:label>
                    <flux:input
                        type="search"
                        wire:model.live.debounce.300ms="medOrdersSearch"
                        placeholder="{{ __('Name, MRN, or token') }}"
                    />
                </flux:field>
            </div>

            @if ($this->selectedBrowseOrder)
                @php($browseOrder = $this->selectedBrowseOrder)
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="flex min-w-0 items-center gap-2 truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                <x-patient-phone-indicator :patient="$browseOrder->patient" />
                                <span class="truncate">{{ $browseOrder->patient?->name ?? __('Unknown') }}</span>
                            </p>
                            <p class="text-xs text-zinc-500">
                                {{ $browseOrder->patient?->mrn ?? __('No MRN') }}
                                · #{{ $browseOrder->queueToken?->token_number }}
                                · {{ $browseOrder->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button type="button" size="sm" variant="ghost" wire:click="clearBrowseOrder">
                                {{ __('Back') }}
                            </flux:button>
                            <flux:button type="button" size="sm" variant="primary" wire:click="repeatOrder({{ $browseOrder->id }})">
                                {{ __('Repeat') }}
                            </flux:button>
                        </div>
                    </div>

                    @if (filled($browseOrder->complaint_or_diagnosis))
                        <flux:badge size="sm" color="sky" class="mb-2">
                            {{ __('Diagnosis') }}: {{ $browseOrder->complaint_or_diagnosis }}
                        </flux:badge>
                    @endif

                    @if ($browseOrder->medicines->isNotEmpty())
                        <div class="mb-2">
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Medicines') }}</p>
                            @foreach ($browseOrder->sortedMedicines() as $medicine)
                                <x-medicine-line
                                    :name="$medicine->name"
                                    :detail="$medicine->dose->label()"
                                    :is-syrup="$medicine->isSyrup()"
                                    class="!bg-transparent !px-0 !py-0 !ring-0 dark:!text-zinc-200"
                                />
                            @endforeach
                        </div>
                    @endif

                    @if ($browseOrder->injections->isNotEmpty())
                        <div class="mb-2">
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Injections') }}</p>
                            @foreach ($browseOrder->injections as $injection)
                                <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                    {{ $injection->name }}
                                    <span class="text-zinc-500">— {{ $injection->administration_type->label() }}</span>
                                </p>
                            @endforeach
                        </div>
                    @endif

                    @if ($browseOrder->drips->isNotEmpty())
                        <div class="mb-2">
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Drips') }}</p>
                            @foreach ($browseOrder->drips as $drip)
                                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $drip->displayName() }}</p>
                                @foreach ($drip->additives as $additive)
                                    <p class="ms-3 text-sm text-zinc-500">+ {{ $additive->displayName() }}</p>
                                @endforeach
                            @endforeach
                        </div>
                    @endif

                    @if ($browseOrder->notes)
                        <p class="mt-2 text-sm text-zinc-500">
                            <span class="font-medium">{{ __('Notes:') }}</span> {{ $browseOrder->notes }}
                        </p>
                    @endif
                </div>
            @else
                <div class="max-h-[60vh] space-y-2 overflow-y-auto pe-1">
                    @forelse ($this->browseableMedOrders as $order)
                        <button
                            type="button"
                            wire:key="browse-order-{{ $order->id }}"
                            wire:click="selectBrowseOrder({{ $order->id }})"
                            class="flex w-full items-start justify-between gap-3 rounded-xl border border-zinc-200 p-3 text-start transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500"
                        >
                            <div class="min-w-0">
                                <p class="flex min-w-0 items-center gap-2 truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                    <x-patient-phone-indicator :patient="$order->patient" />
                                    <span class="truncate">{{ $order->patient?->name ?? __('Unknown') }}</span>
                                </p>
                                <p class="truncate text-xs text-zinc-500">
                                    {{ $order->patient?->mrn ?? __('No MRN') }}
                                    · #{{ $order->queueToken?->token_number }}
                                    · {{ $order->created_at?->timezone(config('app.timezone'))->format('h:i A') }}
                                </p>
                                <p class="mt-1 truncate text-xs text-zinc-600 dark:text-zinc-300">
                                    {{ $order->medicines->pluck('name')->merge($order->injections->pluck('name'))->take(3)->implode(', ') ?: __('No line items') }}
                                </p>
                            </div>
                            <flux:badge size="sm" color="{{ $order->status === \App\Enums\MedicationOrderStatus::Administered ? 'green' : 'zinc' }}">
                                {{ $order->status->label() }}
                            </flux:badge>
                        </button>
                    @empty
                        <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-600">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('No medication orders for this date') }}</p>
                            <p class="mt-1 text-sm text-zinc-500">{{ __('Try another date or clear the search.') }}</p>
                        </div>
                    @endforelse
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeMedOrders">
                    {{ __('Close') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="repeat-order-conflict" wire:model="showRepeatConflictModal" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading level="2">{{ __('Repeat medications') }}</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('This visit already has medications. Append the previous order, or replace what is on the form?') }}
            </p>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button type="button" variant="ghost" wire:click="cancelRepeatConflict">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="button" variant="ghost" wire:click="confirmRepeat('append')">
                    {{ __('Append') }}
                </flux:button>
                <flux:button type="button" variant="primary" wire:click="confirmRepeat('replace')">
                    {{ __('Replace') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
