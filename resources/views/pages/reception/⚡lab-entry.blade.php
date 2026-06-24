<?php

use App\Actions\CreatePrintJob;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Shift;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Lab Entry')] class extends Component
{
    #[Validate]
    public string $patientName = '';

    #[Validate]
    public string $patientPhone = '';

    #[Validate]
    public string $patientGender = '';

    #[Validate]
    public ?int $patientAge = null;

    #[Validate]
    public ?int $selectedLabTestId = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    #[Validate]
    public string $discountPercentage = '0';

    /**
     * Get the validation rules for the lab entry form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'patientName' => ['required', 'string', 'max:255'],
            'patientPhone' => ['required', 'string', 'max:255'],
            'patientGender' => ['required', 'string', 'in:male,female,other'],
            'patientAge' => ['required', 'integer', 'min:0', 'max:150'],
            'selectedLabTestId' => ['required', 'integer', 'exists:lab_tests,id'],
            'discountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * Add the selected lab test to the list.
     */
    public function add(): void
    {
        $validated = $this->validate([
            'patientName' => $this->rules()['patientName'],
            'patientPhone' => $this->rules()['patientPhone'],
            'patientGender' => $this->rules()['patientGender'],
            'patientAge' => $this->rules()['patientAge'],
            'selectedLabTestId' => $this->rules()['selectedLabTestId'],
        ]);

        $labTest = LabTest::find($validated['selectedLabTestId']);

        if (! $labTest instanceof LabTest) {
            Flux::toast(variant: 'danger', text: __('Lab test not found.'));

            return;
        }

        $this->items[] = [
            'lab_test_id' => $labTest->id,
            'test_name' => $labTest->test_name,
            'test_code' => $labTest->test_code,
            'time_required' => $labTest->time_required,
            'is_in_house' => $labTest->is_in_house,
            'test_price' => $labTest->test_price,
        ];

        $this->reset('selectedLabTestId');
        $this->resetValidation('selectedLabTestId');

        Flux::toast(variant: 'success', text: __('Test added.'));
    }

    /**
     * Remove a lab test from the list.
     */
    public function remove(int $index): void
    {
        if (isset($this->items[$index])) {
            unset($this->items[$index]);
            $this->items = array_values($this->items);
        }
    }

    /**
     * Apply the discount percentage to the bill.
     */
    public function applyDiscount(): void
    {
        $this->validate([
            'discountPercentage' => $this->rules()['discountPercentage'],
        ]);

        Flux::toast(variant: 'success', text: __('Discount applied.'));
    }

    /**
     * Clear the form and the selected tests.
     */
    public function clear(): void
    {
        $this->reset([
            'patientName',
            'patientPhone',
            'patientGender',
            'patientAge',
            'selectedLabTestId',
            'items',
            'discountPercentage',
        ]);
        $this->resetValidation();
    }

    /**
     * Save the lab bill as a lab invoice.
     */
    public function save(): void
    {
        $this->validate([
            'patientName' => ['required', 'string', 'max:255'],
            'patientPhone' => ['required', 'string', 'max:255'],
            'patientGender' => ['required', 'string', 'in:male,female,other'],
            'patientAge' => ['required', 'integer', 'min:0', 'max:150'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.lab_test_id' => ['required', 'integer', 'exists:lab_tests,id'],
            'items.*.test_price' => ['required', 'numeric', 'min:0'],
            'discountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $shift = Shift::currentForUser(auth()->id());

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        $invoice = DB::transaction(function () use ($shift) {
            $patient = Patient::create([
                'name' => $this->patientName,
                'phone' => $this->patientPhone,
                'age' => $this->patientAge,
                'gender' => $this->patientGender,
            ]);

            $invoice = LabInvoice::create([
                'patient_id' => $patient->id,
                'invoice_number' => LabInvoice::generateNumber(),
                'subtotal' => $this->subtotal,
                'discount_percentage' => (float) $this->discountPercentage,
                'discount_amount' => $this->discountAmount,
                'total' => $this->total,
                'status' => 'paid',
                'created_by' => auth()->id(),
                'shift_id' => $shift->id,
            ]);

            foreach ($this->items as $item) {
                LabInvoiceItem::create([
                    'lab_invoice_id' => $invoice->id,
                    'lab_test_id' => $item['lab_test_id'],
                    'test_name' => $item['test_name'],
                    'test_code' => $item['test_code'],
                    'time_required' => $item['time_required'],
                    'is_in_house' => $item['is_in_house'],
                    'price' => $item['test_price'],
                ]);
            }

            return $invoice;
        });

        app(CreatePrintJob::class)->create($invoice);

        $this->clear();

        Flux::toast(variant: 'success', text: __('Lab invoice :number saved. Print job queued.', ['number' => $invoice->invoice_number]));
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
     * Get the subtotal of the selected tests.
     */
    #[Computed]
    public function subtotal(): float
    {
        return collect($this->items)->sum('test_price');
    }

    /**
     * Get the discount amount for the current bill.
     */
    #[Computed]
    public function discountAmount(): float
    {
        return $this->subtotal * ((float) $this->discountPercentage / 100);
    }

    /**
     * Get the total price after discount.
     */
    #[Computed]
    public function total(): float
    {
        return $this->subtotal - $this->discountAmount;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Lab Entry') }}</flux:heading>
        </div>

        <flux:card>
            <form wire:submit="add" class="grid grid-cols-1 items-end gap-6 md:grid-cols-12">
                <flux:field class="md:col-span-4">
                    <flux:label>{{ __('Patient name') }}</flux:label>
                    <flux:input wire:model="patientName" type="text" required />
                    <flux:error name="patientName" />
                </flux:field>

                <flux:field class="md:col-span-4">
                    <flux:label>{{ __('Phone number') }}</flux:label>
                    <flux:input wire:model="patientPhone" type="text" required />
                    <flux:error name="patientPhone" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('Age') }}</flux:label>
                    <flux:input wire:model="patientAge" type="number" min="0" max="150" required />
                    <flux:error name="patientAge" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('Gender') }}</flux:label>
                    <flux:select wire:model="patientGender" required>
                        <option value="">{{ __('Select') }}</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </flux:select>
                    <flux:error name="patientGender" />
                </flux:field>

            </form>
        </flux:card>

        <flux:card>
            <flux:heading level="2"> <form wire:submit="add" class="grid grid-cols-1 items-end gap-6 md:grid-cols-12">
                

                <flux:field class="md:col-span-10">
                    <flux:label>{{ __('Test') }}</flux:label>
                    <flux:select wire:model="selectedLabTestId" required>
                        <option value="">{{ __('Select a test') }}</option>
                        @foreach ($this->labTests as $labTest)
                            <option value="{{ $labTest->id }}">{{ $labTest->test_name }} ({{ $labTest->test_code }})</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="selectedLabTestId" />
                </flux:field>

                <div class="md:col-span-2">
                    <flux:button type="submit" variant="primary" icon="plus">
                        {{ __('Add') }}
                    </flux:button>
                </div>
            </form></flux:heading>

            @if ($patientName)
                <div class="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                    <flux:text>{{ __('Patient') }}: {{ $patientName }}</flux:text>
                    <flux:text>{{ __('Phone') }}: {{ $patientPhone }}</flux:text>
                    <flux:text>{{ __('Age') }}: {{ $patientAge }} | {{ __('Gender') }}: {{ ucfirst($patientGender) }}</flux:text>
                </div>
            @endif

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Test') }}</flux:table.column>
                    <flux:table.column>{{ __('Code') }}</flux:table.column>
                    <flux:table.column>{{ __('Price') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->items as $index => $item)
                        <flux:table.row wire:key="lab-item-{{ $index }}">
                            <flux:table.cell>{{ $item['test_name'] }}</flux:table.cell>
                            <flux:table.cell>{{ $item['test_code'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($item['test_price'], 2) }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="remove({{ $index }})"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                {{ __('No tests added yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            @if (count($this->items) > 0)
                <div class="mt-6 space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <flux:field class="w-full sm:max-w-xs">
                            <flux:label>{{ __('Discount (%)') }}</flux:label>
                            <div class="flex gap-2">
                                <flux:input wire:model="discountPercentage" type="number" step="0.01" min="0" max="100" />
                                <flux:button type="button" variant="outline" wire:click="applyDiscount">
                                    {{ __('Apply') }}
                                </flux:button>
                            </div>
                            <flux:error name="discountPercentage" />
                        </flux:field>

                        <div class="text-right">
                            <flux:text class="text-zinc-500">{{ __('Subtotal') }}: {{ number_format($this->subtotal, 2) }}</flux:text>
                            @if ((float) $discountPercentage > 0)
                                <flux:text class="text-zinc-500">{{ __('Discount') }} ({{ number_format((float) $discountPercentage, 2) }}%): -{{ number_format($this->discountAmount, 2) }}</flux:text>
                            @endif
                            <flux:heading level="3">{{ __('Total') }}: {{ number_format($this->total, 2) }}</flux:heading>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <flux:button type="button" variant="primary" icon="document-check" wire:click="save">
                        {{ __('Save invoice') }}
                    </flux:button>

                    <flux:button type="button" variant="ghost" wire:click="clear">
                        {{ __('Clear') }}
                    </flux:button>
                </div>
            @endif
        </flux:card>
    </div>
</div>
