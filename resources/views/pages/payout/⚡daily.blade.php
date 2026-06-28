<?php

use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\InvoiceItem;
use App\Models\Shift;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Daily Payout')] class extends Component
{
    public ?int $viewingDoctorId = null;

    public bool $showDetailModal = false;

    /**
     * Get the doctors who are eligible for daily payout.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::where('payout_daily', true)->orderBy('name')->get();
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
     * Get today's invoice items for the viewed doctor.
     *
     * @return Collection<int, InvoiceItem>
     */
    #[Computed]
    public function todaysItems(): Collection
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return new Collection();
        }

        return InvoiceItem::with('invoice')
            ->where('doctor_id', $doctor->id)
            ->whereDate('created_at', today())
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get the total amount of today's services for the viewed doctor.
     */
    #[Computed]
    public function totalAmount(): float
    {
        return $this->todaysItems->sum('price');
    }

    /**
     * Get the calculated share amounts for each of today's items.
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

        return $doctor->calculateItemShareAmounts($this->todaysItems);
    }

    /**
     * Get the total doctor share for today's services.
     */
    #[Computed]
    public function shareAmount(): float
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return 0.0;
        }

        return $doctor->calculateShareAmount($this->todaysItems);
    }

    /**
     * Determine whether the viewed doctor has already been paid today.
     */
    #[Computed]
    public function isPaidToday(): bool
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return false;
        }

        return DoctorPayout::where('doctor_id', $doctor->id)
            ->whereDate('date', today())
            ->exists();
    }

    /**
     * Get today's payout record for the viewed doctor, if any.
     */
    #[Computed]
    public function todaysPayout(): ?DoctorPayout
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return null;
        }

        return DoctorPayout::where('doctor_id', $doctor->id)
            ->whereDate('date', today())
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
     * Mark the doctor's share for today as paid.
     */
    public function markPaid(): void
    {
        $doctor = $this->viewedDoctor;

        if ($doctor === null) {
            return;
        }

        if ($this->isPaidToday) {
            Flux::toast(variant: 'danger', text: __('This doctor has already been paid today.'));

            return;
        }

        $shift = Shift::currentForUser(auth()->id());

        DoctorPayout::create([
            'doctor_id' => $doctor->id,
            'date' => today(),
            'total_amount' => $this->totalAmount,
            'share_amount' => $this->shareAmount,
            'paid_at' => now(),
            'created_by' => auth()->id(),
            'shift_id' => $shift?->id,
        ]);

        Flux::toast(variant: 'success', text: __('Share paid for :doctor.', ['doctor' => $doctor->name]));
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Daily Payout') }}</flux:heading>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Doctors') }}</flux:heading>
                <flux:text class="text-zinc-500">{{ today()->format('Y-m-d') }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Specialization') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->doctors as $doctor)
                        <flux:table.row wire:key="doctor-{{ $doctor->id }}">
                            <flux:table.cell>{{ $doctor->name }}</flux:table.cell>
                            <flux:table.cell>{{ $doctor->specialization }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($doctor->payouts()->whereDate('date', today())->exists())
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
                                {{ __('No doctors configured for daily payout.') }}
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

                    @if ($this->isPaidToday)
                        <flux:badge size="sm" color="green">{{ __('Paid at :time', ['time' => $this->todaysPayout?->paid_at?->format('H:i')]) }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                    @endif
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Service') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                        <flux:table.column>{{ __('Share %') }}</flux:table.column>
                        <flux:table.column>{{ __('Share Amount') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->todaysItems as $item)
                            <flux:table.row wire:key="item-{{ $item->id }}">
                                <flux:table.cell>{{ $item->service_name }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item->price, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $item->doctor_share !== null ? number_format($item->doctor_share, 2).'%' : '-' }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($this->itemShareAmounts[$item->id] ?? 0, 2) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                    {{ __('No services recorded for this doctor today.') }}
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

                    @if (! $this->isPaidToday)
                        <flux:button type="button" variant="primary" icon="banknotes" wire:click="markPaid" wire:confirm="{{ __('Are you sure you want to mark this share as paid?') }}">
                            {{ __('Share Paid') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>
