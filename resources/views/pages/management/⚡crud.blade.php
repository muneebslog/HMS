<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\LabDoctorShare;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\ProcedureType;
use App\Models\ProcedureTypeDocument;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServicePrice;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Management')] class extends Component
{
    use WithFileUploads;

    private const MAX_DOCUMENT_UPLOADS = 20;

    private const MAX_DOCUMENT_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private const BULK_CATALOG_INITIAL_ROWS = 5;

    public string $activeTab = 'doctors';

    public bool $showModal = false;

    public bool $showDocumentsModal = false;

    public ?int $editingId = null;

    public ?int $documentsProcedureTypeId = null;

    /**
     * @var list<array{name: string, short_form: string, unit: string, is_active: bool}>
     */
    public array $medicineBulkRows = [];

    /**
     * @var list<array{name: string, short_form: string, default_volume_ml: string, is_active: bool}>
     */
    public array $injectionBulkRows = [];

    #[Validate]
    public string $doctorName = '';

    #[Validate]
    public string $doctorSpecialization = '';

    #[Validate]
    public bool $doctorPayoutDaily = false;

    #[Validate]
    public bool $doctorGetFullSlips = false;

    #[Validate]
    public string $doctorFullSlipsCount = '0';

    #[Validate]
    public ?string $doctorDutyStartTime = null;

    #[Validate]
    public bool $doctorIsActive = true;

    #[Validate]
    public string $serviceName = '';

    #[Validate]
    public bool $serviceIsStandalone = false;

    #[Validate]
    public bool $serviceNeedsVitals = false;

    #[Validate]
    public bool $serviceNeedsMedication = false;

    #[Validate]
    public bool $serviceIsDrip = false;

    #[Validate]
    public string $serviceTokenResetType = 'shift';

    #[Validate]
    public bool $serviceIsActive = true;

    #[Validate]
    public ?int $priceServiceId = null;

    #[Validate]
    public ?int $priceDoctorId = null;

    #[Validate]
    public string $priceAmount = '';

    #[Validate]
    public string $priceDoctorShare = '';

    #[Validate]
    public string $priceTokenStartsFrom = '1';

    #[Validate]
    public bool $priceIsFileCheck = false;

    #[Validate]
    public ?int $labShareDoctorId = null;

    #[Validate]
    public string $labSharePercent = '';

    #[Validate]
    public string $labTestName = '';

    #[Validate]
    public ?string $labTestCode = null;

    #[Validate]
    public string $labTestPrice = '';

    #[Validate]
    public ?string $labTestSample = null;

    #[Validate]
    public string $labTestTimeRequired = '';

    #[Validate]
    public bool $labTestIsInHouse = true;

    #[Validate]
    public bool $labTestIsActive = true;

    #[Validate]
    public string $medicineName = '';

    #[Validate]
    public string $medicineShortForm = '';

    #[Validate]
    public string $medicineUnit = '';

    #[Validate]
    public bool $medicineIsActive = true;

    #[Validate]
    public string $injectionName = '';

    #[Validate]
    public string $injectionShortForm = '';

    #[Validate]
    public string $injectionDefaultVolumeMl = '';

    #[Validate]
    public bool $injectionIsActive = true;

    #[Validate]
    public string $dripBaseName = '';

    #[Validate]
    public string $dripBaseDefaultVolumeMl = '';

    #[Validate]
    public bool $dripBaseIsActive = true;

    #[Validate]
    public string $procedureTypeName = '';

    #[Validate]
    public bool $procedureTypeIsActive = true;

    #[Validate]
    public string $roomNumber = '';

    #[Validate]
    public bool $roomIsActive = true;

    /**
     * @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $documentUploads = [];

    /**
     * Get the validation rules for the current tab.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return match ($this->activeTab) {
            'doctors' => [
                'doctorName' => ['required', 'string', 'max:255'],
                'doctorSpecialization' => ['required', 'string', 'max:255'],
                'doctorPayoutDaily' => ['boolean'],
                'doctorGetFullSlips' => ['boolean'],
                'doctorFullSlipsCount' => ['required', 'integer', 'min:0'],
                'doctorDutyStartTime' => ['nullable', 'date_format:H:i'],
                'doctorIsActive' => ['boolean'],
            ],
            'services' => [
                'serviceName' => [
                    'required',
                    'string',
                    'max:255',
                    function ($attribute, $value, $fail) {
                        if (strtolower($value) !== 'consultation') {
                            return;
                        }

                        $existing = \App\Models\Service::whereRaw('LOWER(name) = ?', ['consultation'])
                            ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))
                            ->first();

                        if ($existing !== null) {
                            $fail(__('A service named consultation already exists.'));
                        }
                    },
                ],
                'serviceIsStandalone' => ['boolean'],
                'serviceNeedsVitals' => ['boolean'],
                'serviceNeedsMedication' => ['boolean'],
                'serviceIsDrip' => ['boolean'],
                'serviceTokenResetType' => ['required', 'string', 'in:'.implode(',', array_column(TokenResetType::cases(), 'value'))],
                'serviceIsActive' => ['boolean'],
            ],
            'servicePrices' => [
                'priceServiceId' => ['required', 'integer', 'exists:services,id'],
                'priceDoctorId' => ['nullable', 'integer', 'exists:doctors,id'],
                'priceAmount' => ['required', 'numeric', 'min:0'],
                'priceDoctorShare' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'priceTokenStartsFrom' => ['required', 'integer', 'min:1'],
                'priceIsFileCheck' => ['boolean'],
            ],
            'labDoctorShares' => [
                'labShareDoctorId' => [
                    'required',
                    'integer',
                    'exists:doctors,id',
                    Rule::unique('lab_doctor_shares', 'doctor_id')->ignore($this->editingId),
                ],
                'labSharePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            ],
            'labTests' => [
                'labTestName' => ['required', 'string', 'max:255'],
                'labTestCode' => ['nullable', 'string', 'max:255'],
                'labTestPrice' => ['required', 'numeric', 'min:0'],
                'labTestSample' => ['nullable', 'string', 'max:255'],
                'labTestTimeRequired' => ['required', 'string', 'max:255'],
                'labTestIsInHouse' => ['boolean'],
                'labTestIsActive' => ['boolean'],
            ],
            'medicines' => $this->editingId
                ? [
                    'medicineName' => ['required', 'string', 'max:255'],
                    'medicineShortForm' => ['nullable', 'string', 'max:50'],
                    'medicineUnit' => ['required', 'string', 'max:255'],
                    'medicineIsActive' => ['boolean'],
                ]
                : [
                    'medicineBulkRows' => ['required', 'array', 'min:1'],
                    'medicineBulkRows.*.name' => ['nullable', 'string', 'max:255'],
                    'medicineBulkRows.*.short_form' => ['nullable', 'string', 'max:50'],
                    'medicineBulkRows.*.unit' => ['nullable', 'required_with:medicineBulkRows.*.name', 'string', 'max:255'],
                    'medicineBulkRows.*.is_active' => ['boolean'],
                ],
            'injections' => $this->editingId
                ? [
                    'injectionName' => ['required', 'string', 'max:255'],
                    'injectionShortForm' => ['nullable', 'string', 'max:50'],
                    'injectionDefaultVolumeMl' => ['nullable', 'numeric', 'min:0'],
                    'injectionIsActive' => ['boolean'],
                ]
                : [
                    'injectionBulkRows' => ['required', 'array', 'min:1'],
                    'injectionBulkRows.*.name' => ['nullable', 'string', 'max:255'],
                    'injectionBulkRows.*.short_form' => ['nullable', 'string', 'max:50'],
                    'injectionBulkRows.*.default_volume_ml' => ['nullable', 'numeric', 'min:0'],
                    'injectionBulkRows.*.is_active' => ['boolean'],
                ],
            'dripBases' => [
                'dripBaseName' => ['required', 'string', 'max:255'],
                'dripBaseDefaultVolumeMl' => ['required', 'numeric', 'min:0'],
                'dripBaseIsActive' => ['boolean'],
            ],
            'procedureTypes' => [
                'procedureTypeName' => ['required', 'string', 'max:255', Rule::unique('procedure_types', 'name')->ignore($this->editingId)],
                'procedureTypeIsActive' => ['boolean'],
            ],
            'rooms' => [
                'roomNumber' => ['required', 'string', 'max:255', Rule::unique('rooms', 'number')->ignore($this->editingId)],
                'roomIsActive' => ['boolean'],
            ],
            default => [],
        };
    }

    /**
     * Open the modal to create a new record.
     */
    public function create(): void
    {
        $this->resetForm();
        $this->editingId = null;

        if ($this->activeTab === 'medicines') {
            $this->medicineBulkRows = $this->emptyMedicineBulkRows();
        }

        if ($this->activeTab === 'injections') {
            $this->injectionBulkRows = $this->emptyInjectionBulkRows();
        }

        $this->showModal = true;
    }

    /**
     * @return list<array{name: string, short_form: string, unit: string, is_active: bool}>
     */
    private function emptyMedicineBulkRows(int $count = self::BULK_CATALOG_INITIAL_ROWS): array
    {
        return array_map(fn (): array => $this->emptyMedicineBulkRow(), range(1, $count));
    }

    /**
     * @return array{name: string, short_form: string, unit: string, is_active: bool}
     */
    private function emptyMedicineBulkRow(): array
    {
        return [
            'name' => '',
            'short_form' => '',
            'unit' => '',
            'is_active' => true,
        ];
    }

    /**
     * @return list<array{name: string, short_form: string, default_volume_ml: string, is_active: bool}>
     */
    private function emptyInjectionBulkRows(int $count = self::BULK_CATALOG_INITIAL_ROWS): array
    {
        return array_map(fn (): array => $this->emptyInjectionBulkRow(), range(1, $count));
    }

    /**
     * @return array{name: string, short_form: string, default_volume_ml: string, is_active: bool}
     */
    private function emptyInjectionBulkRow(): array
    {
        return [
            'name' => '',
            'short_form' => '',
            'default_volume_ml' => '',
            'is_active' => true,
        ];
    }

    public function addMedicineBulkRow(): void
    {
        $this->medicineBulkRows[] = $this->emptyMedicineBulkRow();
    }

    public function removeMedicineBulkRow(int $index): void
    {
        if (count($this->medicineBulkRows) <= 1) {
            $this->medicineBulkRows = [$this->emptyMedicineBulkRow()];

            return;
        }

        unset($this->medicineBulkRows[$index]);
        $this->medicineBulkRows = array_values($this->medicineBulkRows);
    }

    public function addInjectionBulkRow(): void
    {
        $this->injectionBulkRows[] = $this->emptyInjectionBulkRow();
    }

    public function removeInjectionBulkRow(int $index): void
    {
        if (count($this->injectionBulkRows) <= 1) {
            $this->injectionBulkRows = [$this->emptyInjectionBulkRow()];

            return;
        }

        unset($this->injectionBulkRows[$index]);
        $this->injectionBulkRows = array_values($this->injectionBulkRows);
    }

    /**
     * Open the modal to edit an existing record.
     */
    public function edit(int $id): void
    {
        $this->resetForm();
        $this->editingId = $id;

        match ($this->activeTab) {
            'doctors' => $this->loadDoctor($id),
            'services' => $this->loadService($id),
            'servicePrices' => $this->loadServicePrice($id),
            'labDoctorShares' => $this->loadLabDoctorShare($id),
            'labTests' => $this->loadLabTest($id),
            'medicines' => $this->loadMedicine($id),
            'injections' => $this->loadInjection($id),
            'dripBases' => $this->loadDripBase($id),
            'procedureTypes' => $this->loadProcedureType($id),
            'rooms' => $this->loadRoom($id),
        };

        $this->showModal = true;
    }

    /**
     * Load doctor data into the form.
     */
    private function loadDoctor(int $id): void
    {
        $doctor = Doctor::findOrFail($id);

        $this->doctorName = $doctor->name;
        $this->doctorSpecialization = $doctor->specialization;
        $this->doctorPayoutDaily = $doctor->payout_daily;
        $this->doctorGetFullSlips = $doctor->get_full_slips;
        $this->doctorFullSlipsCount = (string) $doctor->full_slips_count;
        $this->doctorDutyStartTime = $doctor->duty_start_time?->format('H:i');
        $this->doctorIsActive = $doctor->is_active;
    }

    /**
     * Load service data into the form.
     */
    private function loadService(int $id): void
    {
        $service = Service::findOrFail($id);

        $this->serviceName = $service->name;
        $this->serviceIsStandalone = $service->is_standalone;
        $this->serviceNeedsVitals = $service->needs_vitals;
        $this->serviceNeedsMedication = $service->needs_medication;
        $this->serviceIsDrip = $service->is_drip;
        $this->serviceTokenResetType = $service->token_reset_type->value;
        $this->serviceIsActive = $service->is_active;
    }

    /**
     * Load service price data into the form.
     */
    private function loadServicePrice(int $id): void
    {
        $price = ServicePrice::findOrFail($id);

        $this->priceServiceId = $price->service_id;
        $this->priceDoctorId = $price->doctor_id;
        $this->priceAmount = (string) $price->price;
        $this->priceDoctorShare = $price->doctor_share !== null ? (string) $price->doctor_share : '';
        $this->priceTokenStartsFrom = (string) $price->token_starts_from;
        $this->priceIsFileCheck = $price->is_file_check;
    }

    /**
     * Load lab doctor share data into the form.
     */
    private function loadLabDoctorShare(int $id): void
    {
        $share = LabDoctorShare::findOrFail($id);

        $this->labShareDoctorId = $share->doctor_id;
        $this->labSharePercent = (string) $share->share_percent;
    }

    /**
     * Load lab test data into the form.
     */
    private function loadLabTest(int $id): void
    {
        $labTest = LabTest::findOrFail($id);

        $this->labTestName = $labTest->test_name;
        $this->labTestCode = $labTest->test_code;
        $this->labTestPrice = (string) $labTest->test_price;
        $this->labTestSample = $labTest->sample;
        $this->labTestTimeRequired = $labTest->time_required ?? '';
        $this->labTestIsInHouse = $labTest->is_in_house;
        $this->labTestIsActive = $labTest->is_active;
    }

    /**
     * Load medicine data into the form.
     */
    private function loadMedicine(int $id): void
    {
        $medicine = Medicine::findOrFail($id);

        $this->medicineName = $medicine->name;
        $this->medicineShortForm = $medicine->short_form ?? '';
        $this->medicineUnit = $medicine->unit;
        $this->medicineIsActive = $medicine->is_active;
    }

    /**
     * Load injection data into the form.
     */
    private function loadInjection(int $id): void
    {
        $injection = Injection::findOrFail($id);

        $this->injectionName = $injection->name;
        $this->injectionShortForm = $injection->short_form ?? '';
        $this->injectionDefaultVolumeMl = $injection->default_volume_ml !== null
            ? (string) $injection->default_volume_ml
            : '';
        $this->injectionIsActive = $injection->is_active;
    }

    /**
     * Load drip base data into the form.
     */
    private function loadDripBase(int $id): void
    {
        $dripBase = DripBase::findOrFail($id);

        $this->dripBaseName = $dripBase->name;
        $this->dripBaseDefaultVolumeMl = (string) $dripBase->default_volume_ml;
        $this->dripBaseIsActive = $dripBase->is_active;
    }

    /**
     * Load procedure type data into the form.
     */
    private function loadProcedureType(int $id): void
    {
        $procedureType = ProcedureType::findOrFail($id);

        $this->procedureTypeName = $procedureType->name;
        $this->procedureTypeIsActive = $procedureType->is_active;
    }

    /**
     * Load room data into the form.
     */
    private function loadRoom(int $id): void
    {
        $room = Room::findOrFail($id);

        $this->roomNumber = $room->number;
        $this->roomIsActive = $room->is_active;
    }

    /**
     * Reset all form fields.
     */
    private function resetForm(): void
    {
        $this->reset([
            'doctorName',
            'doctorSpecialization',
            'doctorPayoutDaily',
            'doctorGetFullSlips',
            'doctorFullSlipsCount',
            'doctorDutyStartTime',
            'doctorIsActive',
            'serviceName',
            'serviceIsStandalone',
            'serviceNeedsVitals',
            'serviceNeedsMedication',
            'serviceIsDrip',
            'serviceTokenResetType',
            'serviceIsActive',
            'priceServiceId',
            'priceDoctorId',
            'priceAmount',
            'priceDoctorShare',
            'priceTokenStartsFrom',
            'priceIsFileCheck',
            'labShareDoctorId',
            'labSharePercent',
            'labTestName',
            'labTestCode',
            'labTestPrice',
            'labTestSample',
            'labTestTimeRequired',
            'labTestIsInHouse',
            'labTestIsActive',
            'medicineName',
            'medicineShortForm',
            'medicineUnit',
            'medicineIsActive',
            'injectionName',
            'injectionShortForm',
            'injectionDefaultVolumeMl',
            'injectionIsActive',
            'dripBaseName',
            'dripBaseDefaultVolumeMl',
            'dripBaseIsActive',
            'procedureTypeName',
            'procedureTypeIsActive',
            'roomNumber',
            'roomIsActive',
            'medicineBulkRows',
            'injectionBulkRows',
        ]);

        $this->resetErrorBag();
    }

    /**
     * Store or update the current record.
     */
    public function save(): void
    {
        if ($this->activeTab === 'labTests') {
            if ($this->labTestCode === '') {
                $this->labTestCode = null;
            }

            if ($this->labTestSample === '') {
                $this->labTestSample = null;
            }
        }

        if ($this->activeTab === 'injections' && $this->editingId && $this->injectionDefaultVolumeMl === '') {
            $this->injectionDefaultVolumeMl = '';
        }

        if (! $this->editingId && in_array($this->activeTab, ['medicines', 'injections'], true)) {
            $this->saveBulkCatalog();

            return;
        }

        $validated = $this->validate();

        match ($this->activeTab) {
            'doctors' => $this->saveDoctor($validated),
            'services' => $this->saveService($validated),
            'servicePrices' => $this->saveServicePrice($validated),
            'labDoctorShares' => $this->saveLabDoctorShare($validated),
            'labTests' => $this->saveLabTest($validated),
            'medicines' => $this->saveMedicine($validated),
            'injections' => $this->saveInjection($validated),
            'dripBases' => $this->saveDripBase($validated),
            'procedureTypes' => $this->saveProcedureType($validated),
            'rooms' => $this->saveRoom($validated),
        };

        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Persist multiple medicines or injections from the bulk create form.
     */
    private function saveBulkCatalog(): void
    {
        $validated = $this->validate();

        if ($this->activeTab === 'medicines') {
            $rows = collect($validated['medicineBulkRows'])
                ->filter(fn (array $row): bool => filled($row['name'] ?? null))
                ->values();

            if ($rows->isEmpty()) {
                $this->addError('medicineBulkRows', __('Add at least one medicine.'));

                return;
            }

            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    Medicine::create([
                        'name' => $row['name'],
                        'short_form' => filled($row['short_form'] ?? null) ? $row['short_form'] : null,
                        'unit' => $row['unit'],
                        'is_active' => (bool) ($row['is_active'] ?? true),
                    ]);
                }
            });

            Flux::toast(
                variant: 'success',
                text: trans_choice(':count medicine created.|:count medicines created.', $rows->count(), ['count' => $rows->count()]),
            );
        } else {
            $rows = collect($validated['injectionBulkRows'])
                ->filter(fn (array $row): bool => filled($row['name'] ?? null))
                ->values();

            if ($rows->isEmpty()) {
                $this->addError('injectionBulkRows', __('Add at least one injection.'));

                return;
            }

            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    Injection::create([
                        'name' => $row['name'],
                        'short_form' => filled($row['short_form'] ?? null) ? $row['short_form'] : null,
                        'default_volume_ml' => filled($row['default_volume_ml'] ?? null)
                            ? $row['default_volume_ml']
                            : null,
                        'is_active' => (bool) ($row['is_active'] ?? true),
                    ]);
                }
            });

            Flux::toast(
                variant: 'success',
                text: trans_choice(':count injection created.|:count injections created.', $rows->count(), ['count' => $rows->count()]),
            );
        }

        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Persist doctor data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveDoctor(array $validated): void
    {
        $data = [
            'name' => $validated['doctorName'],
            'specialization' => $validated['doctorSpecialization'],
            'payout_daily' => $validated['doctorPayoutDaily'],
            'get_full_slips' => $validated['doctorGetFullSlips'],
            'full_slips_count' => $validated['doctorFullSlipsCount'],
            'duty_start_time' => $validated['doctorDutyStartTime'] ? $validated['doctorDutyStartTime'].':00' : null,
            'is_active' => $validated['doctorIsActive'],
        ];

        if ($this->editingId) {
            Doctor::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Doctor updated.'));
        } else {
            Doctor::create($data);
            Flux::toast(variant: 'success', text: __('Doctor created.'));
        }
    }

    /**
     * Persist service data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveService(array $validated): void
    {
        $data = [
            'name' => $validated['serviceName'],
            'is_standalone' => $validated['serviceIsStandalone'],
            'needs_vitals' => $validated['serviceNeedsVitals'],
            'needs_medication' => $validated['serviceNeedsMedication'],
            'is_drip' => $validated['serviceIsDrip'],
            'token_reset_type' => $validated['serviceTokenResetType'],
            'is_active' => $validated['serviceIsActive'],
        ];

        if ($this->editingId) {
            Service::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Service updated.'));
        } else {
            Service::create($data);
            Flux::toast(variant: 'success', text: __('Service created.'));
        }
    }

    /**
     * Persist service price data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveServicePrice(array $validated): void
    {
        $data = [
            'service_id' => $validated['priceServiceId'],
            'doctor_id' => $validated['priceDoctorId'],
            'price' => $validated['priceAmount'],
            'doctor_share' => $validated['priceDoctorShare'] !== '' ? $validated['priceDoctorShare'] : null,
            'token_starts_from' => $validated['priceTokenStartsFrom'],
            'is_file_check' => $validated['priceIsFileCheck'] ?? false,
        ];

        if ($this->editingId) {
            ServicePrice::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Service price updated.'));
        } else {
            ServicePrice::create($data);
            Flux::toast(variant: 'success', text: __('Service price created.'));
        }
    }

    /**
     * Persist lab doctor share data.
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveLabDoctorShare(array $validated): void
    {
        $data = [
            'doctor_id' => $validated['labShareDoctorId'],
            'share_percent' => $validated['labSharePercent'],
        ];

        if ($this->editingId) {
            LabDoctorShare::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Lab doctor share updated.'));
        } else {
            LabDoctorShare::create($data);
            Flux::toast(variant: 'success', text: __('Lab doctor share created.'));
        }
    }

    /**
     * Persist lab test data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveLabTest(array $validated): void
    {
        $data = [
            'test_name' => $validated['labTestName'],
            'test_code' => $validated['labTestCode'],
            'test_price' => $validated['labTestPrice'],
            'sample' => $validated['labTestSample'],
            'time_required' => $validated['labTestTimeRequired'],
            'is_in_house' => $validated['labTestIsInHouse'],
            'is_active' => $validated['labTestIsActive'],
        ];

        if ($this->editingId) {
            LabTest::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Lab test updated.'));
        } else {
            LabTest::create($data);
            Flux::toast(variant: 'success', text: __('Lab test created.'));
        }
    }

    /**
     * Persist medicine data (edit only; creates use bulk catalog save).
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveMedicine(array $validated): void
    {
        $data = [
            'name' => $validated['medicineName'],
            'short_form' => filled($validated['medicineShortForm'] ?? null) ? $validated['medicineShortForm'] : null,
            'unit' => $validated['medicineUnit'],
            'is_active' => $validated['medicineIsActive'],
        ];

        Medicine::findOrFail($this->editingId)->update($data);
        Flux::toast(variant: 'success', text: __('Medicine updated.'));
    }

    /**
     * Persist injection data (edit only; creates use bulk catalog save).
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveInjection(array $validated): void
    {
        $data = [
            'name' => $validated['injectionName'],
            'short_form' => filled($validated['injectionShortForm'] ?? null) ? $validated['injectionShortForm'] : null,
            'default_volume_ml' => $validated['injectionDefaultVolumeMl'] !== '' && $validated['injectionDefaultVolumeMl'] !== null
                ? $validated['injectionDefaultVolumeMl']
                : null,
            'is_active' => $validated['injectionIsActive'],
        ];

        Injection::findOrFail($this->editingId)->update($data);
        Flux::toast(variant: 'success', text: __('Injection updated.'));
    }

    /**
     * Persist drip base data.
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveDripBase(array $validated): void
    {
        $data = [
            'name' => $validated['dripBaseName'],
            'default_volume_ml' => $validated['dripBaseDefaultVolumeMl'],
            'is_active' => $validated['dripBaseIsActive'],
        ];

        if ($this->editingId) {
            DripBase::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Drip base updated.'));
        } else {
            DripBase::create($data);
            Flux::toast(variant: 'success', text: __('Drip base created.'));
        }
    }

    /**
     * Persist procedure type data.
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveProcedureType(array $validated): void
    {
        $data = [
            'name' => $validated['procedureTypeName'],
            'is_active' => $validated['procedureTypeIsActive'],
        ];

        if ($this->editingId) {
            ProcedureType::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Procedure type updated.'));
        } else {
            ProcedureType::create($data);
            Flux::toast(variant: 'success', text: __('Procedure type created.'));
        }
    }

    /**
     * Persist room data.
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveRoom(array $validated): void
    {
        $data = [
            'number' => $validated['roomNumber'],
            'is_active' => $validated['roomIsActive'],
        ];

        if ($this->editingId) {
            Room::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Room updated.'));
        } else {
            Room::create($data);
            Flux::toast(variant: 'success', text: __('Room created.'));
        }
    }

    /**
     * Open the documents modal for a procedure type.
     */
    public function openDocuments(int $procedureTypeId): void
    {
        ProcedureType::findOrFail($procedureTypeId);

        $this->documentsProcedureTypeId = $procedureTypeId;
        $this->clearDocumentUploads();
        $this->showDocumentsModal = true;
    }

    /**
     * Close the documents modal.
     */
    public function closeDocumentsModal(): void
    {
        $this->showDocumentsModal = false;
        $this->documentsProcedureTypeId = null;
        $this->clearDocumentUploads();
    }

    /**
     * Discard every staged upload without saving it.
     */
    public function clearDocumentUploads(): void
    {
        foreach ($this->documentUploads as $file) {
            if ($file instanceof TemporaryUploadedFile && $file->exists()) {
                $file->delete();
            }
        }

        $this->documentUploads = [];
        $this->resetValidation();
        $this->dispatch('document-uploads-reset');
    }

    /**
     * Upload one or more documents for the selected procedure type.
     */
    public function uploadDocuments(): void
    {
        $this->validate(
            $this->documentUploadRules(),
            $this->documentUploadMessages(),
            $this->documentUploadAttributes(),
        );

        $procedureType = ProcedureType::findOrFail($this->documentsProcedureTypeId);
        $nextOrder = (int) $procedureType->documents()->max('sort_order') + 1;
        $savedCount = count($this->documentUploads);

        foreach ($this->documentUploads as $file) {
            // Read the metadata before storing: on a shared disk the store moves
            // the temporary file, leaving nothing left to inspect afterwards.
            $originalName = $file->getClientOriginalName();
            $mimeType = $this->resolveDocumentMimeType($file, $originalName);

            ProcedureTypeDocument::create([
                'procedure_type_id' => $procedureType->id,
                'path' => $file->store("procedure-types/{$procedureType->id}/documents", 'local'),
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'sort_order' => $nextOrder++,
            ]);
        }

        $this->documentUploads = [];
        $this->resetValidation();
        $this->dispatch('document-uploads-reset');
        unset($this->documentsProcedureType, $this->procedureTypes);

        Flux::toast(variant: 'success', text: __(':count document(s) uploaded.', ['count' => $savedCount]));
    }

    /**
     * Get the validation rules for the staged document uploads.
     *
     * @return array<string, mixed>
     */
    private function documentUploadRules(): array
    {
        $rules = [
            'documentUploads' => ['required', 'array', 'min:1', 'max:'.self::MAX_DOCUMENT_UPLOADS],
        ];

        foreach (array_keys($this->documentUploads) as $index) {
            $rules["documentUploads.{$index}"] = [
                'file',
                'max:'.intdiv(self::MAX_DOCUMENT_UPLOAD_BYTES, 1024),
                'extensions:'.implode(',', self::ALLOWED_DOCUMENT_EXTENSIONS),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof TemporaryUploadedFile || ! $this->hasSupportedDocumentContents($value)) {
                        $fail(__(':attribute is not a readable PDF, JPG, JPEG, or PNG file.'));
                    }
                },
            ];
        }

        return $rules;
    }

    /**
     * Get the validation messages for the staged document uploads.
     *
     * @return array<string, string>
     */
    private function documentUploadMessages(): array
    {
        return [
            'documentUploads.required' => __('Add at least one file before saving.'),
            'documentUploads.min' => __('Add at least one file before saving.'),
            'documentUploads.max' => __('You can only save :max files at a time.'),
            'documentUploads.*.file' => __(':attribute could not be read. Please add it again.'),
            'documentUploads.*.max' => __(':attribute is too large. Each file must be :size or smaller.', ['size' => $this->maxDocumentUploadSizeForHumans()]),
            'documentUploads.*.extensions' => __(':attribute must be a PDF, JPG, JPEG, or PNG file.'),
        ];
    }

    /**
     * Name each staged upload after its original filename so messages are specific.
     *
     * @return array<string, string>
     */
    private function documentUploadAttributes(): array
    {
        $attributes = ['documentUploads' => __('files')];

        foreach ($this->documentUploads as $index => $file) {
            $attributes["documentUploads.{$index}"] = $file instanceof TemporaryUploadedFile
                ? $file->getClientOriginalName()
                : __('File :number', ['number' => $index + 1]);
        }

        return $attributes;
    }

    /**
     * Get the upload limits and messages the browser needs to pre-check files.
     *
     * @return array{maxFiles: int, maxBytes: int, maxSize: string, extensions: list<string>, messages: array<string, string>}
     */
    #[Computed]
    public function documentUploadConfig(): array
    {
        return [
            'maxFiles' => self::MAX_DOCUMENT_UPLOADS,
            'maxBytes' => self::MAX_DOCUMENT_UPLOAD_BYTES,
            'maxSize' => $this->maxDocumentUploadSizeForHumans(),
            'extensions' => self::ALLOWED_DOCUMENT_EXTENSIONS,
            'messages' => [
                'tooMany' => __('Only :max files can be staged at once. Remove one first.', ['max' => self::MAX_DOCUMENT_UPLOADS]),
                'badExtension' => __('Unsupported file type. Use PDF, JPG, JPEG, or PNG.'),
                'tooLarge' => __('Too large. Each file must be :size or smaller.', ['size' => $this->maxDocumentUploadSizeForHumans()]),
                'rejected' => __('The web server rejected this file. It is most likely bigger than the upload size the server allows.'),
                'cancelled' => __('Upload cancelled.'),
                'nothingToSave' => __('Nothing to save yet'),
                'stillUploading' => __('Waiting for uploads...'),
                'saving' => __('Saving...'),
                'save' => __('Save :count file(s)'),
            ],
        ];
    }

    /**
     * Get every validation message tied to the staged document uploads.
     *
     * @return list<string>
     */
    #[Computed]
    public function documentUploadErrors(): array
    {
        $errors = $this->getErrorBag();

        return collect($errors->get('documentUploads'))
            ->merge(collect($errors->get('documentUploads.*'))->flatten())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Get the maximum upload size in a form that reads well in messages.
     */
    private function maxDocumentUploadSizeForHumans(): string
    {
        return intdiv(self::MAX_DOCUMENT_UPLOAD_BYTES, 1024 * 1024).' MB';
    }

    /**
     * Resolve a usable mime type, falling back to the original file extension.
     */
    private function resolveDocumentMimeType(TemporaryUploadedFile $file, string $originalName): string
    {
        $mimeType = $file->getMimeType();

        if (filled($mimeType) && $mimeType !== 'application/octet-stream') {
            return $mimeType;
        }

        return match (strtolower(pathinfo($originalName, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /**
     * Determine whether an uploaded file has supported document contents.
     */
    private function hasSupportedDocumentContents(TemporaryUploadedFile $file): bool
    {
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $absolutePath = $file->getRealPath();

        if ($extension === 'pdf') {
            $handle = fopen($absolutePath, 'rb');

            if ($handle === false) {
                return false;
            }

            $header = fread($handle, 1024);
            fclose($handle);

            return is_string($header) && str_contains($header, '%PDF-');
        }

        $imageSize = @getimagesize($absolutePath);

        if ($imageSize === false) {
            return false;
        }

        return match ($extension) {
            'jpg', 'jpeg' => $imageSize[2] === IMAGETYPE_JPEG,
            'png' => $imageSize[2] === IMAGETYPE_PNG,
            default => false,
        };
    }

    /**
     * Move a document one position up in the sort order.
     */
    public function moveDocumentUp(int $documentId): void
    {
        $document = ProcedureTypeDocument::query()
            ->where('procedure_type_id', $this->documentsProcedureTypeId)
            ->findOrFail($documentId);

        $previous = ProcedureTypeDocument::query()
            ->where('procedure_type_id', $document->procedure_type_id)
            ->where(function ($query) use ($document) {
                $query->where('sort_order', '<', $document->sort_order)
                    ->orWhere(function ($nested) use ($document) {
                        $nested->where('sort_order', $document->sort_order)
                            ->where('id', '<', $document->id);
                    });
            })
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return;
        }

        $this->swapDocumentOrder($document, $previous);
        unset($this->documentsProcedureType);
    }

    /**
     * Move a document one position down in the sort order.
     */
    public function moveDocumentDown(int $documentId): void
    {
        $document = ProcedureTypeDocument::query()
            ->where('procedure_type_id', $this->documentsProcedureTypeId)
            ->findOrFail($documentId);

        $next = ProcedureTypeDocument::query()
            ->where('procedure_type_id', $document->procedure_type_id)
            ->where(function ($query) use ($document) {
                $query->where('sort_order', '>', $document->sort_order)
                    ->orWhere(function ($nested) use ($document) {
                        $nested->where('sort_order', $document->sort_order)
                            ->where('id', '>', $document->id);
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return;
        }

        $this->swapDocumentOrder($document, $next);
        unset($this->documentsProcedureType);
    }

    /**
     * Swap the sort order of two documents.
     */
    private function swapDocumentOrder(ProcedureTypeDocument $first, ProcedureTypeDocument $second): void
    {
        $firstOrder = $first->sort_order;
        $first->update(['sort_order' => $second->sort_order]);
        $second->update(['sort_order' => $firstOrder]);
    }

    /**
     * Delete a procedure type document and its stored file.
     */
    public function deleteDocument(int $documentId): void
    {
        $document = ProcedureTypeDocument::query()
            ->where('procedure_type_id', $this->documentsProcedureTypeId)
            ->findOrFail($documentId);

        $document->delete();

        unset($this->documentsProcedureType, $this->procedureTypes);

        Flux::toast(variant: 'success', text: __('Document deleted.'));
    }

    /**
     * Delete the current record.
     */
    public function delete(int $id): void
    {
        match ($this->activeTab) {
            'doctors' => Doctor::findOrFail($id)->delete(),
            'services' => Service::findOrFail($id)->delete(),
            'servicePrices' => ServicePrice::findOrFail($id)->delete(),
            'labDoctorShares' => LabDoctorShare::findOrFail($id)->delete(),
            'labTests' => LabTest::findOrFail($id)->delete(),
            'medicines' => Medicine::findOrFail($id)->delete(),
            'injections' => Injection::findOrFail($id)->delete(),
            'dripBases' => DripBase::findOrFail($id)->delete(),
            'procedureTypes' => ProcedureType::findOrFail($id)->delete(),
            'rooms' => Room::findOrFail($id)->delete(),
        };

        Flux::toast(variant: 'success', text: __('Record deleted.'));
    }

    /**
     * Switch the active tab and reset state.
     */
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetForm();
        $this->editingId = null;
        $this->showModal = false;
        $this->closeDocumentsModal();
    }

    /**
     * Get the list of doctors.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::orderBy('name')->get();
    }

    /**
     * Get the list of services.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::orderBy('name')->get();
    }

    /**
     * Get the list of service prices with relations.
     *
     * @return Collection<int, ServicePrice>
     */
    #[Computed]
    public function servicePrices(): Collection
    {
        return ServicePrice::with(['service', 'doctor'])->orderBy('id', 'desc')->get();
    }

    /**
     * Get the list of lab doctor shares.
     *
     * @return Collection<int, LabDoctorShare>
     */
    #[Computed]
    public function labDoctorShares(): Collection
    {
        return LabDoctorShare::with('doctor')->orderBy('id', 'desc')->get();
    }

    /**
     * Get the list of lab tests.
     *
     * @return Collection<int, LabTest>
     */
    #[Computed]
    public function labTests(): Collection
    {
        return LabTest::orderBy('test_name')->get();
    }

    /**
     * Get the list of medicines.
     *
     * @return Collection<int, Medicine>
     */
    #[Computed]
    public function medicines(): Collection
    {
        return Medicine::orderBy('name')->get();
    }

    /**
     * Get the list of injections.
     *
     * @return Collection<int, Injection>
     */
    #[Computed]
    public function injections(): Collection
    {
        return Injection::orderBy('name')->get();
    }

    /**
     * Get the list of drip bases.
     *
     * @return Collection<int, DripBase>
     */
    #[Computed]
    public function dripBases(): Collection
    {
        return DripBase::orderBy('name')->get();
    }

    /**
     * Get the list of procedure types.
     *
     * @return Collection<int, ProcedureType>
     */
    #[Computed]
    public function procedureTypes(): Collection
    {
        return ProcedureType::query()
            ->withCount('documents')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the procedure type currently managing documents.
     */
    #[Computed]
    public function documentsProcedureType(): ?ProcedureType
    {
        if ($this->documentsProcedureTypeId === null) {
            return null;
        }

        return ProcedureType::with('documents')->find($this->documentsProcedureTypeId);
    }

    /**
     * Get the list of rooms.
     *
     * @return Collection<int, Room>
     */
    #[Computed]
    public function rooms(): Collection
    {
        return Room::orderBy('number')->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between" wire:poll.1s>
                <div class="flex items-center gap-3">
                    <flux:heading level="1">{{ __('Management') }}</flux:heading>
                    <flux:badge size="sm" color="zinc" icon="clock">
                        {{ now()->format('Y-m-d H:i:s') }}
                    </flux:badge>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="create">
                    {{ in_array($activeTab, ['medicines', 'injections'], true) ? __('Bulk add') : __('Add new') }}
                </flux:button>
            </div>

            <flux:card>
                <div class="border-b border-zinc-200 dark:border-zinc-700">
                    <nav class="-mb-px flex gap-6" aria-label="Tabs">
                        <button
                            type="button"
                            wire:click="switchTab('doctors')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'doctors' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Doctors') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('services')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'services' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Services') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('servicePrices')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'servicePrices' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Service Prices') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('labTests')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'labTests' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Lab Tests') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('labDoctorShares')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'labDoctorShares' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Lab Doc Share') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('medicines')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'medicines' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Medicines') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('injections')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'injections' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Injections') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('dripBases')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'dripBases' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Drip Bases') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('procedureTypes')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'procedureTypes' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Procedure Types') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('rooms')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'rooms' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Rooms') }}
                        </button>
                    </nav>
                </div>

                <div class="mt-6">
                @if ($activeTab === 'rooms')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Room Number') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->rooms as $room)
                                <flux:table.row wire:key="room-{{ $room->id }}">
                                    <flux:table.cell>{{ $room->number }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="{{ $room->is_active ? 'green' : 'zinc' }}">
                                            {{ $room->is_active ? __('Active') : __('Inactive') }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $room->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $room->id }})" wire:confirm="{{ __('Are you sure you want to delete this room?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="3" class="text-center text-zinc-500">
                                        {{ __('No rooms found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'procedureTypes')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Documents') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->procedureTypes as $procedureType)
                                <flux:table.row wire:key="procedure-type-{{ $procedureType->id }}">
                                    <flux:table.cell>{{ $procedureType->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $procedureType->documents_count }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($procedureType->is_active)
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="document-text" wire:click="openDocuments({{ $procedureType->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $procedureType->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $procedureType->id }})" wire:confirm="{{ __('Are you sure you want to delete this procedure type?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                        {{ __('No procedure types found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'labTests')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Test Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Test Code') }}</flux:table.column>
                            <flux:table.column>{{ __('Specimen') }}</flux:table.column>
                            <flux:table.column>{{ __('Price') }}</flux:table.column>
                            <flux:table.column>{{ __('Time Required') }}</flux:table.column>
                            <flux:table.column>{{ __('In House') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->labTests as $labTest)
                                <flux:table.row wire:key="lab-test-{{ $labTest->id }}">
                                    <flux:table.cell>{{ $labTest->test_name }}</flux:table.cell>
                                    <flux:table.cell>{{ $labTest->test_code }}</flux:table.cell>
                                    <flux:table.cell>{{ $labTest->sample }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($labTest->test_price, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $labTest->time_required }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($labTest->is_in_house)
                                            <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Send out') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($labTest->is_active)
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $labTest->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $labTest->id }})" wire:confirm="{{ __('Are you sure you want to delete this lab test?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                        {{ __('No lab tests found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'labDoctorShares')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                            <flux:table.column>{{ __('Share') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->labDoctorShares as $share)
                                <flux:table.row wire:key="lab-doctor-share-{{ $share->id }}">
                                    <flux:table.cell>{{ $share->doctor->name }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($share->share_percent, 2) }}%</flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $share->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $share->id }})" wire:confirm="{{ __('Are you sure you want to delete this lab doctor share?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="3" class="text-center text-zinc-500">
                                        {{ __('No lab doctor shares found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'doctors')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Specialization') }}</flux:table.column>
                            <flux:table.column>{{ __('Daily Payout') }}</flux:table.column>
                            <flux:table.column>{{ __('Full Slips') }}</flux:table.column>
                            <flux:table.column>{{ __('Duty Start') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->doctors as $doctor)
                                <flux:table.row wire:key="doctor-{{ $doctor->id }}">
                                    <flux:table.cell>{{ $doctor->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $doctor->specialization }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($doctor->payout_daily)
                                            <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($doctor->get_full_slips)
                                            <flux:badge size="sm" color="green">{{ __('First :count', ['count' => $doctor->full_slips_count]) }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $doctor->duty_start_time?->format('g:i A') ?? '-' }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($doctor->is_active)
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $doctor->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $doctor->id }})" wire:confirm="{{ __('Are you sure you want to delete this doctor?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                        {{ __('No doctors found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'services')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Standalone') }}</flux:table.column>
                            <flux:table.column>{{ __('Needs Vitals') }}</flux:table.column>
                            <flux:table.column>{{ __('Needs Medication') }}</flux:table.column>
                            <flux:table.column>{{ __('Is Drip') }}</flux:table.column>
                            <flux:table.column>{{ __('Token Reset') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->services as $service)
                                <flux:table.row wire:key="service-{{ $service->id }}">
                                    <flux:table.cell>{{ $service->name }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($service->is_standalone)
                                            <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($service->needs_vitals)
                                            <flux:badge size="sm" color="amber">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($service->needs_medication)
                                            <flux:badge size="sm" color="sky">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($service->is_drip)
                                            <flux:badge size="sm" color="violet">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $service->token_reset_type->label() }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($service->is_active)
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $service->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $service->id }})" wire:confirm="{{ __('Are you sure you want to delete this service?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                        {{ __('No services found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'medicines')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Short form') }}</flux:table.column>
                            <flux:table.column>{{ __('Unit') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->medicines as $medicine)
                                <flux:table.row wire:key="medicine-{{ $medicine->id }}">
                                    <flux:table.cell>{{ $medicine->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $medicine->short_form ?: '—' }}</flux:table.cell>
                                    <flux:table.cell>{{ $medicine->unit }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="{{ $medicine->is_active ? 'green' : 'zinc' }}">
                                            {{ $medicine->is_active ? __('Active') : __('Inactive') }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $medicine->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $medicine->id }})" wire:confirm="{{ __('Are you sure you want to delete this medicine?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        {{ __('No medicines found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'injections')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Short form') }}</flux:table.column>
                            <flux:table.column>{{ __('Default Volume (ml)') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->injections as $injection)
                                <flux:table.row wire:key="injection-{{ $injection->id }}">
                                    <flux:table.cell>{{ $injection->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $injection->short_form ?: '—' }}</flux:table.cell>
                                    <flux:table.cell>{{ $injection->default_volume_ml !== null ? rtrim(rtrim(number_format($injection->default_volume_ml, 2), '0'), '.') : '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="{{ $injection->is_active ? 'green' : 'zinc' }}">
                                            {{ $injection->is_active ? __('Active') : __('Inactive') }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $injection->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $injection->id }})" wire:confirm="{{ __('Are you sure you want to delete this injection?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        {{ __('No injections found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'dripBases')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Default Volume (ml)') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->dripBases as $dripBase)
                                <flux:table.row wire:key="drip-base-{{ $dripBase->id }}">
                                    <flux:table.cell>{{ $dripBase->name }}</flux:table.cell>
                                    <flux:table.cell>{{ rtrim(rtrim(number_format($dripBase->default_volume_ml, 2), '0'), '.') }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="{{ $dripBase->is_active ? 'green' : 'zinc' }}">
                                            {{ $dripBase->is_active ? __('Active') : __('Inactive') }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $dripBase->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $dripBase->id }})" wire:confirm="{{ __('Are you sure you want to delete this drip base?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                        {{ __('No drip bases found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Service') }}</flux:table.column>
                            <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                            <flux:table.column>{{ __('Price') }}</flux:table.column>
                            <flux:table.column>{{ __('Doctor Share') }}</flux:table.column>
                            <flux:table.column>{{ __('Token starts from') }}</flux:table.column>
                            <flux:table.column>{{ __('File check') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->servicePrices as $price)
                                <flux:table.row wire:key="price-{{ $price->id }}">
                                    <flux:table.cell>{{ $price->service->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $price->doctor?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($price->price, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $price->doctor_share !== null ? number_format($price->doctor_share, 2).'%' : '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $price->token_starts_from }}</flux:table.cell>
                                    <flux:table.cell>{{ $price->is_file_check ? __('Yes') : __('No') }}</flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $price->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $price->id }})" wire:confirm="{{ __('Are you sure you want to delete this service price?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7" class="text-center text-zinc-500">
                                        {{ __('No service prices found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showModal" class="w-full {{ ! $editingId && in_array($activeTab, ['medicines', 'injections'], true) ? 'max-w-4xl' : 'max-w-lg' }}">
        <flux:heading level="2">
            @if ($editingId)
                {{ __('Edit :resource', ['resource' => match($activeTab) { 'doctors' => __('Doctor'), 'services' => __('Service'), 'labTests' => __('Lab Test'), 'labDoctorShares' => __('Lab Doc Share'), 'medicines' => __('Medicine'), 'injections' => __('Injection'), 'dripBases' => __('Drip Base'), 'procedureTypes' => __('Procedure Type'), 'rooms' => __('Room'), default => __('Service Price') }]) }}
            @elseif ($activeTab === 'medicines')
                {{ __('Bulk add medicines') }}
            @elseif ($activeTab === 'injections')
                {{ __('Bulk add injections') }}
            @else
                {{ __('Create :resource', ['resource' => match($activeTab) { 'doctors' => __('Doctor'), 'services' => __('Service'), 'labTests' => __('Lab Test'), 'labDoctorShares' => __('Lab Doc Share'), 'dripBases' => __('Drip Base'), 'procedureTypes' => __('Procedure Type'), 'rooms' => __('Room'), default => __('Service Price') }]) }}
            @endif
        </flux:heading>

        <form wire:submit="save" class="mt-6 space-y-6">
            @if ($activeTab === 'doctors')
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="doctorName" type="text" required />
                    <flux:error name="doctorName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Specialization') }}</flux:label>
                    <flux:input wire:model="doctorSpecialization" type="text" required />
                    <flux:error name="doctorSpecialization" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="doctorPayoutDaily" :label="__('Daily payout')" />
                    <flux:error name="doctorPayoutDaily" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="doctorGetFullSlips" :label="__('Get full slips')" />
                    <flux:error name="doctorGetFullSlips" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Full slips count') }}</flux:label>
                    <flux:input wire:model="doctorFullSlipsCount" type="number" min="0" step="1" />
                    <flux:error name="doctorFullSlipsCount" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Duty start time') }}</flux:label>
                    <flux:input wire:model="doctorDutyStartTime" type="time" />
                    <flux:error name="doctorDutyStartTime" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="doctorIsActive" :label="__('Active')" />
                    <flux:error name="doctorIsActive" />
                </flux:field>
            @elseif ($activeTab === 'services')
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="serviceName" type="text" required />
                    <flux:error name="serviceName" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="serviceIsStandalone" :label="__('Standalone service')" />
                    <flux:error name="serviceIsStandalone" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="serviceNeedsVitals" :label="__('Needs vitals')" />
                    <flux:error name="serviceNeedsVitals" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="serviceNeedsMedication" :label="__('Needs doctor medication')" />
                    <flux:error name="serviceNeedsMedication" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="serviceIsDrip" :label="__('Is drip')" />
                    <flux:error name="serviceIsDrip" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Token reset') }}</flux:label>
                    <flux:select wire:model="serviceTokenResetType" required>
                        @foreach (TokenResetType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="serviceTokenResetType" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="serviceIsActive" :label="__('Active')" />
                    <flux:error name="serviceIsActive" />
                </flux:field>
            @elseif ($activeTab === 'servicePrices')
                <flux:field>
                    <flux:label>{{ __('Service') }}</flux:label>
                    <flux:select wire:model="priceServiceId" required>
                        <option value="">{{ __('Select a service') }}</option>
                        @foreach ($this->services as $service)
                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="priceServiceId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Doctor') }}</flux:label>
                    <flux:select wire:model="priceDoctorId">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->doctors as $doctor)
                            <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="priceDoctorId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Price') }}</flux:label>
                    <flux:input wire:model="priceAmount" type="number" step="0.01" min="0" required />
                    <flux:error name="priceAmount" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Doctor Share (%)') }}</flux:label>
                    <flux:input wire:model="priceDoctorShare" type="number" step="0.01" min="0" max="100" />
                    <flux:error name="priceDoctorShare" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Token starts from') }}</flux:label>
                    <flux:input wire:model="priceTokenStartsFrom" type="number" min="1" step="1" required />
                    <flux:error name="priceTokenStartsFrom" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="priceIsFileCheck" :label="__('File check token')" />
                    <flux:error name="priceIsFileCheck" />
                </flux:field>
            @elseif ($activeTab === 'labDoctorShares')
                <flux:field>
                    <flux:label>{{ __('Doctor') }}</flux:label>
                    <flux:select wire:model="labShareDoctorId" required>
                        <option value="">{{ __('Select a doctor') }}</option>
                        @foreach ($this->doctors as $doctor)
                            <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="labShareDoctorId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Share (%)') }}</flux:label>
                    <flux:input wire:model="labSharePercent" type="number" step="0.01" min="0" max="100" required />
                    <flux:error name="labSharePercent" />
                </flux:field>
            @elseif ($activeTab === 'procedureTypes')
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="procedureTypeName" type="text" required />
                    <flux:error name="procedureTypeName" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="procedureTypeIsActive" :label="__('Active')" />
                    <flux:error name="procedureTypeIsActive" />
                </flux:field>
            @elseif ($activeTab === 'rooms')
                <flux:field>
                    <flux:label>{{ __('Room Number') }}</flux:label>
                    <flux:input wire:model="roomNumber" type="text" required />
                    <flux:error name="roomNumber" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="roomIsActive" :label="__('Active')" />
                    <flux:error name="roomIsActive" />
                </flux:field>
            @elseif ($activeTab === 'medicines')
                @if ($editingId)
                    <flux:field>
                        <flux:label>{{ __('Name') }}</flux:label>
                        <flux:input wire:model="medicineName" type="text" required />
                        <flux:error name="medicineName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Short form') }}</flux:label>
                        <flux:input wire:model="medicineShortForm" type="text" placeholder="{{ __('e.g. PCM') }}" />
                        <flux:error name="medicineShortForm" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Unit') }}</flux:label>
                        <flux:input wire:model="medicineUnit" type="text" required />
                        <flux:error name="medicineUnit" />
                    </flux:field>

                    <flux:field>
                        <flux:switch wire:model="medicineIsActive" :label="__('Active')" />
                        <flux:error name="medicineIsActive" />
                    </flux:field>
                @else
                    <div class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                        @foreach ($medicineBulkRows as $index => $row)
                            <div wire:key="medicine-bulk-{{ $index }}" class="grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12">
                                <div class="sm:col-span-4">
                                    <flux:input wire:model="medicineBulkRows.{{ $index }}.name" type="text" placeholder="{{ __('Name') }}" />
                                    <flux:error name="medicineBulkRows.{{ $index }}.name" />
                                </div>
                                <div class="sm:col-span-2">
                                    <flux:input wire:model="medicineBulkRows.{{ $index }}.short_form" type="text" placeholder="{{ __('Short form') }}" />
                                    <flux:error name="medicineBulkRows.{{ $index }}.short_form" />
                                </div>
                                <div class="sm:col-span-3">
                                    <flux:input wire:model="medicineBulkRows.{{ $index }}.unit" type="text" placeholder="{{ __('Unit') }}" />
                                    <flux:error name="medicineBulkRows.{{ $index }}.unit" />
                                </div>
                                <div class="flex items-center sm:col-span-2">
                                    <flux:switch wire:model="medicineBulkRows.{{ $index }}.is_active" :label="__('Active')" />
                                </div>
                                <div class="flex items-start justify-end sm:col-span-1">
                                    <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeMedicineBulkRow({{ $index }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <flux:error name="medicineBulkRows" />
                    <flux:button type="button" variant="ghost" icon="plus" wire:click="addMedicineBulkRow">
                        {{ __('Add row') }}
                    </flux:button>
                @endif
            @elseif ($activeTab === 'injections')
                @if ($editingId)
                    <flux:field>
                        <flux:label>{{ __('Name') }}</flux:label>
                        <flux:input wire:model="injectionName" type="text" required />
                        <flux:error name="injectionName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Short form') }}</flux:label>
                        <flux:input wire:model="injectionShortForm" type="text" placeholder="{{ __('e.g. DIC') }}" />
                        <flux:error name="injectionShortForm" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Default Volume (ml)') }}</flux:label>
                        <flux:input wire:model="injectionDefaultVolumeMl" type="number" step="0.01" min="0" />
                        <flux:error name="injectionDefaultVolumeMl" />
                    </flux:field>

                    <flux:field>
                        <flux:switch wire:model="injectionIsActive" :label="__('Active')" />
                        <flux:error name="injectionIsActive" />
                    </flux:field>
                @else
                    <div class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                        @foreach ($injectionBulkRows as $index => $row)
                            <div wire:key="injection-bulk-{{ $index }}" class="grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12">
                                <div class="sm:col-span-4">
                                    <flux:input wire:model="injectionBulkRows.{{ $index }}.name" type="text" placeholder="{{ __('Name') }}" />
                                    <flux:error name="injectionBulkRows.{{ $index }}.name" />
                                </div>
                                <div class="sm:col-span-2">
                                    <flux:input wire:model="injectionBulkRows.{{ $index }}.short_form" type="text" placeholder="{{ __('Short form') }}" />
                                    <flux:error name="injectionBulkRows.{{ $index }}.short_form" />
                                </div>
                                <div class="sm:col-span-3">
                                    <flux:input wire:model="injectionBulkRows.{{ $index }}.default_volume_ml" type="number" step="0.01" min="0" placeholder="{{ __('Volume ml') }}" />
                                    <flux:error name="injectionBulkRows.{{ $index }}.default_volume_ml" />
                                </div>
                                <div class="flex items-center sm:col-span-2">
                                    <flux:switch wire:model="injectionBulkRows.{{ $index }}.is_active" :label="__('Active')" />
                                </div>
                                <div class="flex items-start justify-end sm:col-span-1">
                                    <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeInjectionBulkRow({{ $index }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <flux:error name="injectionBulkRows" />
                    <flux:button type="button" variant="ghost" icon="plus" wire:click="addInjectionBulkRow">
                        {{ __('Add row') }}
                    </flux:button>
                @endif
            @elseif ($activeTab === 'dripBases')
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="dripBaseName" type="text" required />
                    <flux:error name="dripBaseName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Default Volume (ml)') }}</flux:label>
                    <flux:input wire:model="dripBaseDefaultVolumeMl" type="number" step="0.01" min="0" required />
                    <flux:error name="dripBaseDefaultVolumeMl" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="dripBaseIsActive" :label="__('Active')" />
                    <flux:error name="dripBaseIsActive" />
                </flux:field>
            @elseif ($activeTab === 'labTests')
                <flux:field>
                    <flux:label>{{ __('Test Name') }}</flux:label>
                    <flux:input wire:model="labTestName" type="text" required />
                    <flux:error name="labTestName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Test Code') }}</flux:label>
                    <flux:input wire:model="labTestCode" type="text"  />
                    <flux:error name="labTestCode" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Specimen') }}</flux:label>
                    <flux:input wire:model="labTestSample" type="text" />
                    <flux:error name="labTestSample" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Price') }}</flux:label>
                    <flux:input wire:model="labTestPrice" type="number" step="0.01" min="0" required />
                    <flux:error name="labTestPrice" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Time Required') }}</flux:label>
                    <flux:input wire:model="labTestTimeRequired" type="text" required />
                    <flux:error name="labTestTimeRequired" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="labTestIsInHouse" :label="__('In house test')" />
                    <flux:error name="labTestIsInHouse" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="labTestIsActive" :label="__('Active')" />
                    <flux:error name="labTestIsActive" />
                </flux:field>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? __('Update') : __('Save') }}
                </flux:button>
            </div>
            </form>
    </flux:modal>

    <flux:modal wire:model="showDocumentsModal" class="w-full max-w-4xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Documents') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $this->documentsProcedureType?->name ?? __('Procedure type documents') }}
                </flux:text>
            </div>

            <form
                class="space-y-4"
                x-data="{
                    config: @js($this->documentUploadConfig),
                    items: [],
                    lastKey: 0,
                    dragging: false,
                    saving: false,

                    get uploading() { return this.items.filter((item) => item.status === 'uploading') },
                    get ready() { return this.items.filter((item) => item.status === 'ready') },
                    get failed() { return this.items.filter((item) => item.status === 'failed') },

                    get saveLabel() {
                        if (this.saving) return this.config.messages.saving
                        if (this.uploading.length) return this.config.messages.stillUploading
                        if (! this.ready.length) return this.config.messages.nothingToSave

                        return this.config.messages.save.replace(':count', this.ready.length)
                    },

                    forget() {
                        this.items.forEach((item) => item.previewUrl && URL.revokeObjectURL(item.previewUrl))
                        this.items = []
                    },

                    readableSize(bytes) {
                        if (bytes < 1024) return bytes + ' B'
                        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB'

                        return (bytes / 1024 / 1024).toFixed(1) + ' MB'
                    },

                    stage(file, wire) {
                        let extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : ''

                        let item = {
                            key: ++this.lastKey,
                            name: file.name,
                            size: this.readableSize(file.size),
                            isImage: ['jpg', 'jpeg', 'png'].includes(extension),
                            isPdf: extension === 'pdf',
                            previewUrl: null,
                            tmpFilename: null,
                            progress: 0,
                            status: 'failed',
                            error: null,
                        }

                        if (this.items.length >= this.config.maxFiles) {
                            return this.items.push({ ...item, error: this.config.messages.tooMany })
                        }

                        if (! this.config.extensions.includes(extension)) {
                            return this.items.push({ ...item, error: this.config.messages.badExtension })
                        }

                        if (file.size > this.config.maxBytes) {
                            return this.items.push({ ...item, error: this.config.messages.tooLarge })
                        }

                        this.items.push({
                            ...item,
                            status: 'uploading',
                            previewUrl: URL.createObjectURL(file),
                        })

                        let key = item.key
                        let patch = (changes) => {
                            let staged = this.items.find((candidate) => candidate.key === key)

                            if (staged) Object.assign(staged, changes)
                        }

                        wire.$upload(
                            'documentUploads',
                            file,
                            (tmpFilename) => patch({ status: 'ready', progress: 100, tmpFilename }),
                            () => patch({ status: 'failed', progress: 0, error: this.config.messages.rejected }),
                            (event) => patch({ progress: event.total ? Math.round((event.loaded / event.total) * 100) : 0 }),
                            () => patch({ status: 'failed', progress: 0, error: this.config.messages.cancelled }),
                        )
                    },

                    discard(item, wire) {
                        let drop = () => {
                            if (item.previewUrl) URL.revokeObjectURL(item.previewUrl)

                            this.items = this.items.filter((candidate) => candidate.key !== item.key)
                        }

                        if (item.tmpFilename) {
                            return wire.$removeUpload('documentUploads', item.tmpFilename, drop)
                        }

                        drop()
                    },

                    async save(wire) {
                        this.saving = true

                        try {
                            await wire.uploadDocuments()
                        } finally {
                            this.saving = false
                        }
                    },
                }"
                x-on:document-uploads-reset.window="forget()"
                x-on:submit.prevent="save($wire)"
            >
                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors"
                    x-bind:class="dragging
                        ? 'border-blue-400 bg-blue-50 dark:border-blue-500 dark:bg-blue-950/30'
                        : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-600 dark:hover:border-zinc-500'"
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="
                        dragging = false;
                        Array.from($event.dataTransfer.files).forEach((file) => stage(file, $wire));
                    "
                >
                    <flux:icon icon="arrow-up-tray" class="size-6 text-zinc-400" />

                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                        {{ __('Click to choose files, or drop them here') }}
                    </span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('PDF, JPG, JPEG or PNG. Up to :size each, :max files at a time.', [
                            'size' => $this->documentUploadConfig['maxSize'],
                            'max' => $this->documentUploadConfig['maxFiles'],
                        ]) }}
                    </span>

                    <input
                        type="file"
                        class="sr-only"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        x-on:change="
                            Array.from($event.target.files).forEach((file) => stage(file, $wire));
                            $event.target.value = '';
                        "
                    />
                </label>

                @if ($this->documentUploadErrors !== [])
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Nothing was saved') }}</flux:callout.heading>
                        <flux:callout.text>
                            <ul class="list-inside list-disc space-y-1">
                                @foreach ($this->documentUploadErrors as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div x-show="items.length" style="display: none" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <template x-for="item in items" :key="item.key">
                        <div
                            class="overflow-hidden rounded-lg border bg-zinc-50 dark:bg-zinc-900"
                            x-bind:class="item.status === 'failed'
                                ? 'border-red-300 dark:border-red-800'
                                : 'border-zinc-200 dark:border-zinc-700'"
                        >
                            <img
                                x-show="item.isImage && item.previewUrl"
                                x-bind:src="item.previewUrl"
                                x-bind:alt="item.name"
                                class="h-36 w-full object-contain"
                            />
                            <iframe
                                x-show="item.isPdf && item.previewUrl"
                                x-bind:src="item.previewUrl"
                                x-bind:title="item.name"
                                class="h-36 w-full bg-white"
                            ></iframe>
                            <div x-show="! item.previewUrl" class="flex h-36 w-full items-center justify-center">
                                <flux:icon icon="document" class="size-8 text-zinc-400" />
                            </div>

                            <div class="space-y-2 border-t border-zinc-200 p-3 dark:border-zinc-700">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-200" x-text="item.name"></p>
                                        <p class="text-xs text-zinc-500" x-text="item.size"></p>
                                    </div>

                                    <button
                                        type="button"
                                        class="shrink-0 cursor-pointer rounded p-1 text-zinc-400 transition-colors hover:bg-zinc-200 hover:text-zinc-700 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                        x-bind:disabled="item.status === 'uploading'"
                                        x-bind:aria-label="@js(__('Remove')) + ' ' + item.name"
                                        x-on:click="discard(item, $wire)"
                                    >
                                        <flux:icon icon="x-mark" class="size-4" />
                                    </button>
                                </div>

                                <div x-show="item.status === 'uploading'" class="space-y-1">
                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div
                                            class="h-full rounded-full bg-blue-500 transition-[width] duration-150"
                                            x-bind:style="'width: ' + item.progress + '%'"
                                        ></div>
                                    </div>
                                    <p class="text-xs text-zinc-500">
                                        <span x-text="item.progress"></span>% &middot; {{ __('uploading') }}
                                    </p>
                                </div>

                                <p x-show="item.status === 'ready'" class="flex items-center gap-1 text-xs font-medium text-green-600 dark:text-green-400">
                                    <flux:icon icon="check-circle" class="size-4 shrink-0" />
                                    {{ __('Uploaded, ready to save') }}
                                </p>

                                <p x-show="item.status === 'failed'" class="flex items-start gap-1 text-xs font-medium text-red-600 dark:text-red-400">
                                    <flux:icon icon="exclamation-triangle" class="size-4 shrink-0" />
                                    <span x-text="item.error"></span>
                                </p>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span x-show="! items.length" class="text-zinc-500">{{ __('No files added yet.') }}</span>
                        <span x-show="ready.length" style="display: none" class="text-green-600 dark:text-green-400">
                            <span x-text="ready.length"></span> {{ __('ready') }}
                        </span>
                        <span x-show="uploading.length" style="display: none" class="text-zinc-500">
                            <span x-text="uploading.length"></span> {{ __('uploading') }}
                        </span>
                        <span x-show="failed.length" style="display: none" class="text-red-600 dark:text-red-400">
                            <span x-text="failed.length"></span> {{ __('failed') }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="ghost"
                            :loading="false"
                            x-show="items.length"
                            style="display: none"
                            x-on:click="forget(); $wire.clearDocumentUploads()"
                        >
                            {{ __('Clear') }}
                        </flux:button>

                        <flux:button
                            type="submit"
                            variant="primary"
                            :loading="false"
                            disabled
                            x-bind:disabled="saving || uploading.length > 0 || ready.length === 0"
                        >
                            <span class="flex items-center gap-2">
                                <flux:icon icon="loading" class="size-4" x-show="saving" style="display: none" />
                                <flux:icon icon="arrow-up-tray" class="size-4" x-show="! saving" />
                                <span x-text="saveLabel">{{ __('Save') }}</span>
                            </span>
                        </flux:button>
                    </div>
                </div>
            </form>

            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Linked documents') }}</flux:heading>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @forelse ($this->documentsProcedureType?->documents ?? [] as $document)
                    <div
                        wire:key="procedure-type-document-{{ $document->id }}"
                        class="overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        @if ($document->isImage())
                            <img
                                src="{{ route('management.procedure-type-documents.preview', $document) }}"
                                alt="{{ $document->original_name }}"
                                loading="lazy"
                                class="h-40 w-full object-contain"
                            />
                        @elseif ($document->isPdf())
                            <iframe
                                src="{{ route('management.procedure-type-documents.preview', $document) }}#toolbar=0"
                                title="{{ $document->original_name }}"
                                loading="lazy"
                                class="h-40 w-full bg-white"
                            ></iframe>
                        @endif

                        <div class="flex items-center justify-between gap-3 border-t border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="min-w-0">
                                <flux:text class="truncate font-medium">{{ $document->original_name }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">{{ $document->resolvedMimeType() }}</flux:text>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    :href="route('management.procedure-type-documents.preview', $document)"
                                    target="_blank"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-up"
                                    wire:click="moveDocumentUp({{ $document->id }})"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-down"
                                    wire:click="moveDocumentDown({{ $document->id }})"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="deleteDocument({{ $document->id }})"
                                    wire:confirm="{{ __('Are you sure you want to delete this document?') }}"
                                />
                            </div>
                        </div>
                    </div>
                @empty
                    <flux:text class="text-zinc-500">{{ __('No documents linked yet.') }}</flux:text>
                @endforelse
                </div>
            </div>

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeDocumentsModal">
                    {{ __('Close') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
