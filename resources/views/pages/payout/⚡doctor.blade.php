<?php

use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Doctor Payout')] class extends Component
{
    public string $fromDate = '';

    public string $toDate = '';

    public ?int $viewingDoctorId = null;

    public bool $showDetailModal = false;

    /**
     * Initialize the component state.
     */
    public function mount(): void
    {
        $this->fromDate = today()->toDateString();
        $this->toDate = today()->toDateString();
    }

    /**
     * Get all doctors ordered by name.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::orderBy('name')->get();
    }

    /**
     * Get the doctor IDs that already have an overlapping payout for the selected range.
     *
     * @return SupportCollection<int, int>
     */
    #[Computed]
    public function paidDoctorIds(): SupportCollection
    {
        return DoctorPayout::whereDate('from_date', '<=', $this->toDate)
            ->whereDate('to_date', '>=', $this->fromDate)
            ->pluck('doctor_id');
    }

    /**
     * Get the doctor currently being viewed.
     */
    #[Computed]
    public function viewedDoctor(): ?Doctor
    {
        if ($this->viewingDoctorId === null) {
            return null;
        }

        return Doctor::find($this->viewingDoctorId);
    }

    /**
     * Get the invoice items for the viewed doctor within the selected date range.
     *
     * @return Collection<int, InvoiceItem>
     */
    #[Computed]
    public function items(): Collection
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return new Collection();
        }

        return InvoiceItem::with('invoice')
            ->where('doctor_id', $doctor->id)
            ->whereBetween('created_at', [
                Carbon::parse($this->fromDate)->startOfDay(),
                Carbon::parse($this->toDate)->endOfDay(),
            ])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get the total service amount for the viewed doctor within the selected range.
     */
    #[Computed]
    public function totalAmount(): float
    {
        return $this->items->sum('price');
    }

    /**
     * Get the calculated share amounts for each item in the selected range.
     *
     * @return array<int, float>
     */
    #[Computed]
    public function itemShareAmounts(): array
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return [];
        }

        return $doctor->calculateItemShareAmounts($this->items, perDay: true);
    }

    /**
     * Get the total doctor share for the viewed doctor within the selected range.
     */
    #[Computed]
    public function shareAmount(): float
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return 0.0;
        }

        return $doctor->calculateShareAmount($this->items, perDay: true);
    }

    /**
     * Determine whether the viewed doctor has already been paid for the selected range.
     */
    #[Computed]
    public function isPaidForRange(): bool
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return false;
        }

        return DoctorPayout::where('doctor_id', $doctor->id)
            ->whereDate('from_date', '<=', $this->toDate)
            ->whereDate('to_date', '>=', $this->fromDate)
            ->exists();
    }

    /**
     * Get the existing payout for the viewed doctor and selected range, if any.
     */
    #[Computed]
    public function existingPayout(): ?DoctorPayout
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return null;
        }

        return DoctorPayout::where('doctor_id', $doctor->id)
            ->whereDate('from_date', '<=', $this->toDate)
            ->whereDate('to_date', '>=', $this->fromDate)
            ->first();
    }

    /**
     * Open the detail modal for the selected doctor.
     */
    public function viewDoctor(int $id): void
    {
        $this->viewingDoctorId = $id;
        $this->showDetailModal = true;
    }

    /**
     * Close the detail modal.
     */
    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->viewingDoctorId = null;
    }

    /**
     * Validate the selected date range.
     */
    private function validateRange(): bool
    {
        $validator = Validator::make(
            ['fromDate' => $this->fromDate, 'toDate' => $this->toDate],
            [
                'fromDate' => ['required', 'date'],
                'toDate' => ['required', 'date', 'after_or_equal:fromDate'],
            ]
        );

        if ($validator->fails()) {
            Flux::toast(variant: 'danger', text: __('The "From" date must be before or equal to the "To" date.'));

            return false;
        }

        return true;
    }

    /**
     * Mark the doctor's share for the selected range as paid.
     */
    public function markPaid(): void
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return;
        }

        if (! $this->validateRange()) {
            return;
        }

        if ($this->isPaidForRange) {
            Flux::toast(variant: 'danger', text: __('This doctor has already been paid for the selected date range.'));

            return;
        }

        DoctorPayout::create([
            'doctor_id' => $doctor->id,
            'date' => today(),
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'total_amount' => $this->totalAmount,
            'share_amount' => $this->shareAmount,
            'paid_at' => now(),
            'created_by' => auth()->id(),
        ]);

        Flux::toast(variant: 'success', text: __('Share paid for :doctor.', ['doctor' => $doctor->name]));
        $this->closeDetailModal();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Doctor Payout') }}</flux:heading>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <flux:field>
                        <flux:label>{{ __('From') }}</flux:label>
                        <flux:input wire:model.live="fromDate" type="date" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('To') }}</flux:label>
                        <flux:input wire:model.live="toDate" type="date" />
                    </flux:field>
                </div>

                <flux:text class="text-zinc-500">
                    {{ $this->fromDate }} {{ __('to') }} {{ $this->toDate }}
                </flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Specialization') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @php($paidIds = $this->paidDoctorIds)
                    @forelse ($this->doctors as $doctor)
                        <flux:table.row wire:key="doctor-{{ $doctor->id }}">
                            <flux:table.cell>{{ $doctor->name }}</flux:table.cell>
                            <flux:table.cell>{{ $doctor->specialization }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($paidIds->contains($doctor->id))
                                    <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button size="sm" variant="ghost" icon="calculator" wire:click="viewDoctor({{ $doctor->id }})">
                                    {{ __('Calculate') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                {{ __('No doctors found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showDetailModal" class="w-full max-w-3xl">
        @if ($this->viewedDoctor)
            <div class="flex flex-col gap-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ $this->viewedDoctor->name }}</flux:heading>
                        <flux:text class="text-zinc-500">{{ $this->viewedDoctor->specialization }}</flux:text>
                    </div>

                    @if ($this->isPaidForRange)
                        <flux:badge size="sm" color="green">
                            {{ __('Paid at :time', ['time' => $this->existingPayout?->paid_at?->format('H:i')]) }}
                        </flux:badge>
                    @else
                        <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                    @endif
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Service') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                        <flux:table.column>{{ __('Share %') }}</flux:table.column>
                        <flux:table.column>{{ __('Share Amount') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->items as $item)
                            <flux:table.row wire:key="item-{{ $item->id }}">
                                <flux:table.cell>{{ $item->service_name }}</flux:table.cell>
                                <flux:table.cell>{{ $item->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item->price, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $item->doctor_share !== null ? number_format($item->doctor_share, 2).'%' : '-' }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($this->itemShareAmounts[$item->id] ?? 0, 2) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                    {{ __('No services recorded for this doctor in the selected range.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:card>
                        <flux:text class="text-zinc-500">{{ __('Total Services') }}</flux:text>
                        <flux:heading level="3">{{ number_format($this->totalAmount, 2) }}</flux:heading>
                    </flux:card>

                    <flux:card>
                        <flux:text class="text-zinc-500">{{ __('Doctor Share') }}</flux:text>
                        <flux:heading level="3">{{ number_format($this->shareAmount, 2) }}</flux:heading>
                    </flux:card>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="closeDetailModal">
                        {{ __('Close') }}
                    </flux:button>

                    @if (! $this->isPaidForRange)
                        <flux:button type="button" variant="primary" icon="banknotes" wire:click="markPaid" wire:confirm="{{ __('Are you sure you want to mark this share as paid?') }}">
                            {{ __('Share Paid') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
