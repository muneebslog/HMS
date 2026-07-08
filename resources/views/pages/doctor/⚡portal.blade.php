<?php

use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Doctor Portal')] class extends Component
{
    public string $fromDate = '';

    public string $toDate = '';

    /**
     * Initialize the component state.
     */
    public function mount(): void
    {
        $this->fromDate = now()->startOfMonth()->toDateString();
        $this->toDate = now()->endOfMonth()->toDateString();
    }

    /**
     * Get the logged-in doctor's profile.
     */
    #[Computed]
    public function doctor(): ?Doctor
    {
        return auth()->user()?->doctor;
    }

    /**
     * Get the invoice items for the doctor within the selected date range.
     *
     * @return Collection<int, InvoiceItem>
     */
    #[Computed]
    public function items(): Collection
    {
        $doctor = $this->doctor;

        if ($doctor === null) {
            return new Collection();
        }

        return InvoiceItem::with(['invoice.patient'])
            ->where('doctor_id', $doctor->id)
            ->whereBetween('created_at', [
                Carbon::parse($this->fromDate)->startOfDay(),
                Carbon::parse($this->toDate)->endOfDay(),
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get the share amount for each invoice item.
     *
     * @return array<int, float>
     */
    #[Computed]
    public function itemShareAmounts(): array
    {
        $doctor = $this->doctor;

        if ($doctor === null) {
            return [];
        }

        return $doctor->calculateItemShareAmounts($this->items, perDay: true);
    }

    /**
     * Get the total calculated share for the selected range.
     */
    #[Computed]
    public function totalShare(): float
    {
        $doctor = $this->doctor;

        if ($doctor === null) {
            return 0.0;
        }

        return $doctor->calculateShareAmount($this->items, perDay: true);
    }

    /**
     * Get the number of distinct patients checked in the selected range.
     */
    #[Computed]
    public function patientsChecked(): int
    {
        return $this->items
            ->pluck('invoice.patient_id')
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * Get the number of services performed in the selected range.
     */
    #[Computed]
    public function servicesPerformed(): int
    {
        return $this->items->count();
    }

    /**
     * Get the total paid share for the selected range.
     */
    #[Computed]
    public function paidShare(): float
    {
        $doctor = $this->doctor;

        if ($doctor === null) {
            return 0.0;
        }

        return (float) DoctorPayout::where('doctor_id', $doctor->id)
            ->whereBetween('date', [
                Carbon::parse($this->fromDate)->startOfDay(),
                Carbon::parse($this->toDate)->endOfDay(),
            ])
            ->sum('share_amount');
    }

    /**
     * Get the pending share for the selected range.
     */
    #[Computed]
    public function pendingShare(): float
    {
        return max($this->totalShare - $this->paidShare, 0.0);
    }

    /**
     * Get the payout history for the selected range.
     *
     * @return Collection<int, DoctorPayout>
     */
    #[Computed]
    public function payouts(): Collection
    {
        $doctor = $this->doctor;

        if ($doctor === null) {
            return new Collection();
        }

        return DoctorPayout::where('doctor_id', $doctor->id)
            ->whereBetween('date', [
                Carbon::parse($this->fromDate)->startOfDay(),
                Carbon::parse($this->toDate)->endOfDay(),
            ])
            ->orderByDesc('date')
            ->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Doctor Portal') }}</flux:heading>
                @if ($this->doctor)
                    <flux:text class="text-zinc-500">
                        {{ $this->doctor->name }} — {{ $this->doctor->specialization }}
                    </flux:text>
                @endif
            </div>
        </div>

        <flux:card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <flux:field>
                    <flux:label>{{ __('From') }}</flux:label>
                    <flux:input wire:model.live="fromDate" type="date" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('To') }}</flux:label>
                    <flux:input wire:model.live="toDate" type="date" />
                </flux:field>
            </div>
        </flux:card>

        @if ($this->doctor === null)
            <flux:card>
                <flux:text class="text-zinc-500">
                    {{ __('Your account is not linked to a doctor profile yet. Please contact an administrator.') }}
                </flux:text>
            </flux:card>
        @else
            <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:card>
                    <flux:text class="text-zinc-500">{{ __('Patients Checked') }}</flux:text>
                    <flux:heading level="3">{{ $this->patientsChecked }}</flux:heading>
                </flux:card>

                <flux:card>
                    <flux:text class="text-zinc-500">{{ __('Services Performed') }}</flux:text>
                    <flux:heading level="3">{{ $this->servicesPerformed }}</flux:heading>
                </flux:card>

                <flux:card>
                    <flux:text class="text-zinc-500">{{ __('Total Share') }}</flux:text>
                    <flux:heading level="3">{{ number_format($this->totalShare, 2) }}</flux:heading>
                </flux:card>

                <flux:card>
                    <flux:text class="text-zinc-500">{{ __('Pending Share') }}</flux:text>
                    <flux:heading level="3">{{ number_format($this->pendingShare, 2) }}</flux:heading>
                </flux:card>
            </div>

            <div class="grid auto-rows-min gap-4 lg:grid-cols-3">
                <flux:card class="lg:col-span-2">
                    <flux:heading level="2" class="mb-4">{{ __('Recent Activity') }}</flux:heading>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Service') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                            <flux:table.column>{{ __('Price') }}</flux:table.column>
                            <flux:table.column>{{ __('Share %') }}</flux:table.column>
                            <flux:table.column>{{ __('Share Amount') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->items as $item)
                                <flux:table.row wire:key="item-{{ $item->id }}">
                                    <flux:table.cell>{{ $item->service_name }}</flux:table.cell>
                                    <flux:table.cell>{{ $item->invoice?->patient?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $item->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($item->price, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $item->doctor_share !== null ? number_format($item->doctor_share, 2).'%' : '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($this->itemShareAmounts[$item->id] ?? 0, 2) }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                                        {{ __('No activity recorded in the selected range.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </flux:card>

                <flux:card>
                    <flux:heading level="2" class="mb-4">{{ __('Payout History') }}</flux:heading>

                    @if ($this->payouts->isEmpty())
                        <flux:text class="text-zinc-500">
                            {{ __('No payouts recorded in the selected range.') }}
                        </flux:text>
                    @else
                        <div class="space-y-4">
                            @foreach ($this->payouts as $payout)
                                <div class="flex items-center justify-between border-b border-zinc-200 pb-3 last:border-0 last:pb-0 dark:border-zinc-700" wire:key="payout-{{ $payout->id }}">
                                    <div>
                                        <flux:text class="font-medium">{{ $payout->date->format('Y-m-d') }}</flux:text>
                                        <flux:text class="text-xs text-zinc-500">
                                            {{ $payout->from_date->format('Y-m-d') }} — {{ $payout->to_date->format('Y-m-d') }}
                                        </flux:text>
                                    </div>
                                    <flux:heading level="4">{{ number_format($payout->share_amount, 2) }}</flux:heading>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </flux:card>
            </div>
        @endif
    </div>
</div>
