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

    public string $activeOrderTab = 'medicines';

    public string $notes = '';

    public string $suggestedPrice = '';

    public ?int $dripServiceId = null;

    public string $recheckMinutes = '15';

    public string $recheckNote = '';

    public bool $showRecheckForm = false;

    /**
     * @var list<array{medicine_id: int|null, dose: string, days: string}>
     */
    public array $medicineLines = [];

    /**
     * @var list<array{injection_id: int|null, administration_type: string, volume_ml: string}>
     */
    public array $injectionLines = [];

    /**
     * @var list<array{drip_base_id: int|null, volume_ml: string, additives: list<array{injection_id: int|null, volume_ml: string}>}>
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
                    ->where('shift_id', $shift->id)
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
                $label = $medicine->name.' ('.$medicine->unit.')';

                if (filled($medicine->short_form)) {
                    $label = $medicine->short_form.' — '.$label;
                }

                return [
                    'value' => $medicine->id,
                    'label' => $label,
                    'keywords' => trim($medicine->name.' '.$medicine->unit.' '.($medicine->short_form ?? '')),
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
        $this->notes = '';
        $this->suggestedPrice = '';
        $this->dripServiceId = null;
        $this->recheckMinutes = '15';
        $this->recheckNote = '';
        $this->showRecheckForm = false;
        $this->medicineLines = [];
        $this->injectionLines = [];
        $this->dripLines = [];
        $this->resetValidation();
    }

    public function switchOrderTab(string $tab): void
    {
        if (! in_array($tab, ['medicines', 'injections', 'drips'], true)) {
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
            'medicines' => $this->addMedicineLine(),
            'injections' => $this->addInjectionLine(),
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
            'medicines' => $this->medicineLines === [] ? $this->addMedicineLine() : null,
            'injections' => $this->injectionLines === [] ? $this->addInjectionLine() : null,
            'drips' => $this->dripLines === [] ? $this->addDripLine() : null,
            default => null,
        };
    }

    public function addMedicineLine(): void
    {
        $this->medicineLines[] = [
            'medicine_id' => null,
            'dose' => MedicineDose::OneZeroZero->value,
            'days' => '3',
        ];
    }

    public function removeMedicineLine(int $index): void
    {
        unset($this->medicineLines[$index]);
        $this->medicineLines = array_values($this->medicineLines);
    }

    public function addInjectionLine(): void
    {
        $this->injectionLines[] = [
            'injection_id' => null,
            'administration_type' => InjectionAdministrationType::Im->value,
            'volume_ml' => '',
        ];
    }

    public function removeInjectionLine(int $index): void
    {
        unset($this->injectionLines[$index]);
        $this->injectionLines = array_values($this->injectionLines);
    }

    public function updatedInjectionLines(mixed $value, ?string $key): void
    {
        if (! is_string($key) || ! str_ends_with($key, '.injection_id')) {
            return;
        }

        $index = (int) explode('.', $key)[0];
        $injectionId = $this->injectionLines[$index]['injection_id'] ?? null;

        if ($injectionId === null || $injectionId === '') {
            return;
        }

        $injection = $this->injections->firstWhere('id', (int) $injectionId);

        if ($injection?->default_volume_ml !== null && ($this->injectionLines[$index]['volume_ml'] ?? '') === '') {
            $this->injectionLines[$index]['volume_ml'] = (string) $injection->default_volume_ml;
        }
    }

    public function addDripLine(): void
    {
        $this->dripLines[] = [
            'drip_base_id' => null,
            'volume_ml' => '',
            'additives' => [],
        ];
    }

    public function removeDripLine(int $index): void
    {
        unset($this->dripLines[$index]);
        $this->dripLines = array_values($this->dripLines);
    }

    public function updatedDripLines(mixed $value, ?string $key): void
    {
        if (! is_string($key) || ! str_ends_with($key, '.drip_base_id')) {
            return;
        }

        $index = (int) explode('.', $key)[0];
        $dripBaseId = $this->dripLines[$index]['drip_base_id'] ?? null;

        if ($dripBaseId === null || $dripBaseId === '') {
            return;
        }

        $dripBase = $this->dripBases->firstWhere('id', (int) $dripBaseId);

        if ($dripBase !== null && ($this->dripLines[$index]['volume_ml'] ?? '') === '') {
            $this->dripLines[$index]['volume_ml'] = (string) $dripBase->default_volume_ml;
        }
    }

    public function addDripAdditive(int $dripIndex): void
    {
        if (! isset($this->dripLines[$dripIndex])) {
            return;
        }

        $this->dripLines[$dripIndex]['additives'][] = [
            'injection_id' => null,
            'volume_ml' => '',
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
     * Save or update the medication order for the selected token.
     */
    public function save(): void
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

        $existing = $token->medicationOrder;

        if ($existing !== null && $existing->status === MedicationOrderStatus::Administered) {
            Flux::toast(variant: 'danger', text: __('This order has already been administered and cannot be edited.'));
            $this->backToList();

            return;
        }

        $validated = $this->validate($this->orderRules());

        $medicineLines = collect($validated['medicineLines'] ?? [])
            ->filter(fn (array $line): bool => filled($line['medicine_id'] ?? null))
            ->values();
        $injectionLines = collect($validated['injectionLines'] ?? [])
            ->filter(fn (array $line): bool => filled($line['injection_id'] ?? null))
            ->values();
        $dripLines = collect($validated['dripLines'] ?? [])
            ->filter(fn (array $line): bool => filled($line['drip_base_id'] ?? null))
            ->values();

        if ($medicineLines->isEmpty() && $injectionLines->isEmpty() && $dripLines->isEmpty()) {
            $this->addError('medicineLines', __('Add at least one medicine, injection, or drip.'));

            return;
        }

        $medicinesById = Medicine::query()
            ->whereIn('id', $medicineLines->pluck('medicine_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $injectionsById = Injection::query()
            ->whereIn(
                'id',
                $injectionLines->pluck('injection_id')
                    ->merge($dripLines->flatMap(fn (array $drip) => collect($drip['additives'] ?? [])->pluck('injection_id')))
                    ->filter()
                    ->all()
            )
            ->get()
            ->keyBy('id');
        $dripBasesById = DripBase::query()
            ->whereIn('id', $dripLines->pluck('drip_base_id')->filter()->all())
            ->get()
            ->keyBy('id');

        DB::transaction(function () use ($token, $validated, $medicineLines, $injectionLines, $dripLines, $medicinesById, $injectionsById, $dripBasesById, $existing): void {
            $order = $existing ?? new MedicationOrder([
                'queue_token_id' => $token->id,
                'patient_id' => $token->patient_id,
            ]);

            $order->fill([
                'doctor_id' => $token->serviceQueue?->doctor_id,
                'prescribed_by' => auth()->id(),
                'status' => MedicationOrderStatus::Pending,
                'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
                'administered_by' => null,
                'administered_at' => null,
            ]);
            $order->save();

            $order->medicines()->delete();
            $order->injections()->delete();
            $order->drips()->delete();

            foreach ($medicineLines as $line) {
                $medicine = $medicinesById->get((int) $line['medicine_id']);

                if ($medicine === null) {
                    continue;
                }

                $order->medicines()->create([
                    'medicine_id' => $medicine->id,
                    'dose' => $line['dose'],
                    'days' => (int) $line['days'],
                    'name' => $medicine->name,
                ]);
            }

            foreach ($injectionLines as $line) {
                $injection = $injectionsById->get((int) $line['injection_id']);

                if ($injection === null) {
                    continue;
                }

                $order->injections()->create([
                    'injection_id' => $injection->id,
                    'administration_type' => $line['administration_type'],
                    'volume_ml' => filled($line['volume_ml'] ?? null) ? $line['volume_ml'] : null,
                    'name' => $injection->name,
                ]);
            }

            foreach ($dripLines as $line) {
                $dripBase = $dripBasesById->get((int) $line['drip_base_id']);

                if ($dripBase === null) {
                    continue;
                }

                $drip = $order->drips()->create([
                    'drip_base_id' => $dripBase->id,
                    'volume_ml' => $line['volume_ml'],
                    'name' => $dripBase->name,
                ]);

                foreach ($line['additives'] ?? [] as $additive) {
                    if (! filled($additive['injection_id'] ?? null)) {
                        continue;
                    }

                    $injection = $injectionsById->get((int) $additive['injection_id']);

                    if ($injection === null) {
                        continue;
                    }

                    $drip->additives()->create([
                        'injection_id' => $injection->id,
                        'volume_ml' => $additive['volume_ml'],
                        'name' => $injection->name,
                    ]);
                }
            }

            $this->syncDripCharge($order, $token, $validated);
        });

        unset($this->queue, $this->selectedToken);

        Flux::toast(variant: 'success', text: __('Medication order saved.'));
        $this->backToList();
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
            'notes' => ['nullable', 'string', 'max:2000'],
            'suggestedPrice' => ['nullable', 'numeric', 'min:0'],
            'dripServiceId' => [
                'nullable',
                'required_with:suggestedPrice',
                'integer',
                Rule::exists('services', 'id')->where(fn ($query) => $query->where('is_drip', true)->where('is_active', true)),
            ],
            'medicineLines' => ['array'],
            'medicineLines.*.medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
            'medicineLines.*.dose' => ['required_with:medicineLines.*.medicine_id', 'string', Rule::enum(MedicineDose::class)],
            'medicineLines.*.days' => ['required_with:medicineLines.*.medicine_id', 'integer', 'min:1', 'max:365'],
            'injectionLines' => ['array'],
            'injectionLines.*.injection_id' => ['nullable', 'integer', 'exists:injections,id'],
            'injectionLines.*.administration_type' => ['required_with:injectionLines.*.injection_id', 'string', Rule::enum(InjectionAdministrationType::class)],
            'injectionLines.*.volume_ml' => ['nullable', 'numeric', 'min:0'],
            'dripLines' => ['array'],
            'dripLines.*.drip_base_id' => ['nullable', 'integer', 'exists:drip_bases,id'],
            'dripLines.*.volume_ml' => ['required_with:dripLines.*.drip_base_id', 'numeric', 'min:0'],
            'dripLines.*.additives' => ['array'],
            'dripLines.*.additives.*.injection_id' => ['nullable', 'integer', 'exists:injections,id'],
            'dripLines.*.additives.*.volume_ml' => ['required_with:dripLines.*.additives.*.injection_id', 'numeric', 'min:0'],
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
            $this->medicineLines = [[
                'medicine_id' => null,
                'dose' => MedicineDose::OneZeroZero->value,
                'days' => '3',
            ]];
            $this->injectionLines = [];
            $this->dripLines = [];

            return;
        }

        $this->notes = $order->notes ?? '';
        $this->medicineLines = $order->medicines->map(fn ($line) => [
            'medicine_id' => $line->medicine_id,
            'dose' => $line->dose->value,
            'days' => (string) $line->days,
        ])->values()->all();

        if ($this->medicineLines === []) {
            $this->medicineLines = [[
                'medicine_id' => null,
                'dose' => MedicineDose::OneZeroZero->value,
                'days' => '3',
            ]];
        }

        $this->injectionLines = $order->injections->map(fn ($line) => [
            'injection_id' => $line->injection_id,
            'administration_type' => $line->administration_type->value,
            'volume_ml' => $line->volume_ml !== null ? (string) $line->volume_ml : '',
        ])->values()->all();

        $this->dripLines = $order->drips->map(fn ($drip) => [
            'drip_base_id' => $drip->drip_base_id,
            'volume_ml' => (string) $drip->volume_ml,
            'additives' => $drip->additives->map(fn ($additive) => [
                'injection_id' => $additive->injection_id,
                'volume_ml' => (string) $additive->volume_ml,
            ])->values()->all(),
        ])->values()->all();
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
        <div class="flex flex-1 flex-col gap-2">
            @forelse ($this->queue as $token)
                @php($recheck = $token->activeRecheck)
                <button
                    type="button"
                    wire:key="medication-token-{{ $token->id }}"
                    wire:click="selectToken({{ $token->id }})"
                    class="flex w-full items-center gap-4 rounded-xl border bg-white px-4 py-4 text-left shadow-sm transition active:scale-[0.99] dark:bg-zinc-800 {{ $recheck?->isDue() ? 'border-amber-400 dark:border-amber-500' : 'border-zinc-200 dark:border-zinc-700' }}"
                >
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-xl font-bold text-white dark:bg-white dark:text-zinc-900">
                        {{ $token->token_number }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="block truncate text-lg font-semibold text-zinc-900 dark:text-white">
                                {{ $token->patient?->name ?? __('Unknown') }}
                            </span>
                            @if ($recheck?->isDue())
                                <flux:badge size="sm" color="amber">{{ __('Again') }}</flux:badge>
                            @elseif ($recheck)
                                <flux:badge size="sm" color="zinc">{{ __('Recheck :time', ['time' => $recheck->due_at->timezone(config('app.timezone'))->format('h:i A')]) }}</flux:badge>
                            @endif
                        </span>
                        <span class="mt-0.5 block truncate text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $token->patient?->mrn ?? __('No MRN') }}
                            · {{ $token->serviceQueue?->service?->name }}
                            @if ($token->medicationOrder)
                                · {{ $token->medicationOrder->status->label() }}
                            @endif
                            @if ($recheck?->isDue())
                                · {{ __('Again') }}{{ filled($recheck->note) ? ' — '.$recheck->note : '' }}
                            @endif
                        </span>
                    </span>
                    <flux:icon name="chevron-right" class="size-5 shrink-0 text-zinc-400" />
                </button>
            @empty
                <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
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
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Time') }}</flux:table.column>
                            <flux:table.column>{{ __('Temp (°F)') }}</flux:table.column>
                            <flux:table.column>{{ __('BP') }}</flux:table.column>
                            <flux:table.column>{{ __('BSR') }}</flux:table.column>
                            <flux:table.column>{{ __('By') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($token->vitals as $vital)
                                <flux:table.row wire:key="vital-{{ $vital->id }}">
                                    <flux:table.cell>{{ $vital->created_at->timezone(config('app.timezone'))->format('d M, g:i A') }}</flux:table.cell>
                                    <flux:table.cell>{{ $vital->temperature }}</flux:table.cell>
                                    <flux:table.cell>{{ $vital->bp_systolic }}/{{ $vital->bp_diastolic }}</flux:table.cell>
                                    <flux:table.cell>{{ $vital->bsr ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>{{ $vital->recordedBy?->name ?? '—' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
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

        <div class="border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-4">
                @foreach (['medicines' => __('Medicines'), 'injections' => __('Injections'), 'drips' => __('Drips')] as $tab => $label)
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
            wire:submit="save"
            class="flex flex-1 flex-col gap-4"
            x-data
            @keydown.shift.enter.prevent="$wire.addRowForActiveTab()"
        >
            @if ($activeOrderTab === 'medicines')
                <div class="space-y-3">
                    @foreach ($medicineLines as $index => $line)
                        <div wire:key="medicine-line-{{ $index }}" class="grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12">
                            <div class="sm:col-span-5">
                                <x-searchable-select
                                    wire:model="medicineLines.{{ $index }}.medicine_id"
                                    :options="$this->medicineOptions"
                                    :placeholder="__('Search medicine')"
                                />
                                <flux:error name="medicineLines.{{ $index }}.medicine_id" />
                            </div>
                            <div class="sm:col-span-3">
                                <flux:select wire:model="medicineLines.{{ $index }}.dose">
                                    @foreach (\App\Enums\MedicineDose::cases() as $dose)
                                        <option value="{{ $dose->value }}">{{ $dose->label() }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="medicineLines.{{ $index }}.dose" />
                            </div>
                            <div class="sm:col-span-3">
                                <flux:input wire:model="medicineLines.{{ $index }}.days" type="number" min="1" max="365" placeholder="{{ __('Days') }}" />
                                <flux:error name="medicineLines.{{ $index }}.days" />
                            </div>
                            <div class="flex items-start sm:col-span-1">
                                <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeMedicineLine({{ $index }})" />
                            </div>
                        </div>
                    @endforeach
                    <flux:error name="medicineLines" />
                    <flux:tooltip :content="__('Shift+Enter')" position="top">
                        <flux:button type="button" variant="ghost" icon="plus" wire:click="addMedicineLine">{{ __('Add medicine') }}</flux:button>
                    </flux:tooltip>
                </div>
            @elseif ($activeOrderTab === 'injections')
                <div class="space-y-3">
                    @foreach ($injectionLines as $index => $line)
                        <div wire:key="injection-line-{{ $index }}" class="grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12">
                            <div class="sm:col-span-5">
                                <x-searchable-select
                                    wire:model.live="injectionLines.{{ $index }}.injection_id"
                                    :options="$this->injectionOptions"
                                    :placeholder="__('Search injection')"
                                />
                            </div>
                            <div class="sm:col-span-3">
                                <flux:select wire:model="injectionLines.{{ $index }}.administration_type">
                                    @foreach (\App\Enums\InjectionAdministrationType::cases() as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="injectionLines.{{ $index }}.administration_type" />
                            </div>
                            <div class="sm:col-span-3">
                                <flux:input wire:model="injectionLines.{{ $index }}.volume_ml" type="number" step="0.01" min="0" placeholder="{{ __('Volume ml') }}" />
                            </div>
                            <div class="flex items-start sm:col-span-1">
                                <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeInjectionLine({{ $index }})" />
                            </div>
                        </div>
                    @endforeach
                    <flux:tooltip :content="__('Shift+Enter')" position="top">
                        <flux:button type="button" variant="ghost" icon="plus" wire:click="addInjectionLine">{{ __('Add injection') }}</flux:button>
                    </flux:tooltip>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($dripLines as $dripIndex => $drip)
                        <div wire:key="drip-line-{{ $dripIndex }}" class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="grid gap-2 sm:grid-cols-12">
                                <div class="sm:col-span-7">
                                    <x-searchable-select
                                        wire:model.live="dripLines.{{ $dripIndex }}.drip_base_id"
                                        :options="$this->dripBaseOptions"
                                        :placeholder="__('Search drip base')"
                                    />
                                </div>
                                <div class="sm:col-span-4">
                                    <flux:input wire:model="dripLines.{{ $dripIndex }}.volume_ml" type="number" step="0.01" min="0" placeholder="{{ __('Volume ml') }}" />
                                </div>
                                <div class="flex items-start sm:col-span-1">
                                    <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeDripLine({{ $dripIndex }})" />
                                </div>
                            </div>

                            <div class="space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Additives') }}</p>
                                @foreach ($drip['additives'] ?? [] as $additiveIndex => $additive)
                                    <div wire:key="drip-{{ $dripIndex }}-additive-{{ $additiveIndex }}" class="grid gap-2 sm:grid-cols-12">
                                        <div class="sm:col-span-7">
                                            <x-searchable-select
                                                wire:model="dripLines.{{ $dripIndex }}.additives.{{ $additiveIndex }}.injection_id"
                                                :options="$this->injectionOptions"
                                                :placeholder="__('Search injection')"
                                            />
                                        </div>
                                        <div class="sm:col-span-4">
                                            <flux:input wire:model="dripLines.{{ $dripIndex }}.additives.{{ $additiveIndex }}.volume_ml" type="number" step="0.01" min="0" placeholder="{{ __('ml') }}" />
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
                            <div class="grid gap-3 sm:grid-cols-2">
                                @if ($this->dripServices->count() > 1)
                                    <flux:field>
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

                                <flux:field class="{{ $this->dripServices->count() > 1 ? '' : 'sm:col-span-2' }}">
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

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="notes" rows="2" />
                <flux:error name="notes" />
            </flux:field>

            <div class="mt-auto flex flex-col gap-3 pt-2">
                <flux:button type="submit" variant="primary" class="h-12 w-full text-base font-semibold">
                    {{ __('Save order') }}
                </flux:button>
                <flux:button type="button" variant="ghost" wire:click="backToList" class="w-full">
                    {{ __('Back to list') }}
                </flux:button>
            </div>
        </form>
    @endif

    <flux:modal wire:model="showHistoryModal" class="w-full max-w-2xl">
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

                        @if ($order->medicines->isNotEmpty())
                            <div class="mb-2">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Medicines') }}</p>
                                @foreach ($order->medicines as $medicine)
                                    <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                        {{ $medicine->name }}
                                        <span class="text-zinc-500">— {{ $medicine->dose->label() }} · {{ $medicine->days }} {{ __('days') }}</span>
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
                                            @if ($injection->volume_ml !== null)
                                                · {{ rtrim(rtrim(number_format($injection->volume_ml, 2), '0'), '.') }} ml
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
                                        {{ $drip->name }} — {{ rtrim(rtrim(number_format($drip->volume_ml, 2), '0'), '.') }} ml
                                    </p>
                                    @foreach ($drip->additives as $additive)
                                        <p class="ms-3 text-sm text-zinc-500">
                                            + {{ rtrim(rtrim(number_format($additive->volume_ml, 2), '0'), '.') }} ml {{ $additive->name }}
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
