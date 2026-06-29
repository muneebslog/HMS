<?php

use App\Models\Doctor;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Doctor Reservations')] class extends Component
{
    public ?int $selectedDoctorId = null;

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function currentShift(): ?Shift
    {
        return Shift::current();
    }

    /**
     * Get the consultation service.
     */
    #[Computed]
    public function consultationService(): ?Service
    {
        return Service::whereRaw('LOWER(name) = ?', ['consultation'])->first();
    }

    /**
     * Get the doctors that can perform the consultation service.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        $service = $this->consultationService;

        if ($service === null) {
            return new Collection();
        }

        if ($service->is_standalone) {
            return Doctor::orderBy('name')->get();
        }

        return Doctor::whereHas('servicePrices', function ($query) use ($service) {
            $query->where('service_id', $service->id);
        })->orderBy('name')->get();
    }

    /**
     * Get the current open queue for the selected doctor and consultation service.
     */
    #[Computed]
    public function currentQueue(): ?ServiceQueue
    {
        $service = $this->consultationService;
        $shift = $this->currentShift;

        if ($service === null || $shift === null || $this->selectedDoctorId === null) {
            return null;
        }

        $query = ServiceQueue::where('service_id', $service->id)
            ->where('doctor_id', $this->selectedDoctorId)
            ->where('status', 'open');

        return match ($service->token_reset_type->value) {
            'shift' => $query->where('shift_id', $shift->id)->first(),
            default => $query->whereDate('date', $shift->opened_at)->first(),
        };
    }

    /**
     * Get the reserved tokens for the selected doctor's current queue.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function reservations(): Collection
    {
        $queue = $this->currentQueue;

        if ($queue === null) {
            return new Collection();
        }

        return QueueToken::with(['patient', 'serviceQueue.doctor'])
            ->where('service_queue_id', $queue->id)
            ->where('status', 'reserved')
            ->orderBy('token_number')
            ->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Doctor Reservations') }}</flux:heading>
        </div>

        @if (! $this->currentShift)
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('No Open Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">{{ __('Open a shift to view doctor reservations.') }}</flux:text>
                    </div>
                    <flux:button variant="primary" icon="lock-open" :href="route('reception.shift')" wire:navigate>
                        {{ __('Open Shift') }}
                    </flux:button>
                </div>
            </flux:card>
        @elseif ($this->consultationService === null)
            <flux:card>
                <flux:heading level="2">{{ __('Consultation Service Missing') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Please create a service named "consultation" in Management first.') }}
                </flux:text>
            </flux:card>
        @else
            <flux:card>
                <div class="grid grid-cols-1 items-end gap-6 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Service') }}</flux:label>
                        <flux:input type="text" value="{{ $this->consultationService->name }}" disabled />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Doctor') }}</flux:label>
                        <flux:select wire:model.live="selectedDoctorId" required>
                            <option value="">{{ __('Select a doctor') }}</option>
                            @foreach ($this->doctors as $doctor)
                                <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>
            </flux:card>

            @if ($this->selectedDoctorId)
                <flux:card>
                    <flux:heading level="2" class="mb-4">{{ __('Reservations') }}</flux:heading>

                    @if ($this->reservations->isEmpty())
                        <flux:text class="text-zinc-500">{{ __('No reservations found for this doctor.') }}</flux:text>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-zinc-200 dark:border-zinc-700">
                                    <tr>
                                        <th class="py-3 pr-4 font-semibold">{{ __('Token #') }}</th>
                                        <th class="py-3 pr-4 font-semibold">{{ __('Patient Name') }}</th>
                                        <th class="py-3 pr-4 font-semibold">{{ __('Phone') }}</th>
                                        <th class="py-3 pr-4 font-semibold">{{ __('Reserved At') }}</th>
                                        <th class="py-3 font-semibold">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($this->reservations as $reservation)
                                        <tr wire:key="reservation-{{ $reservation->id }}">
                                            <td class="py-3 pr-4">{{ $reservation->token_number }}</td>
                                            <td class="py-3 pr-4">{{ $reservation->patient->name }}</td>
                                            <td class="py-3 pr-4">{{ $reservation->patient->phone }}</td>
                                            <td class="py-3 pr-4">{{ $reservation->created_at->format('Y-m-d H:i') }}</td>
                                            <td class="py-3">
                                                <flux:button
                                                    icon="phone"
                                                    size="sm"
                                                    :href="'tel:' . $reservation->patient->phone"
                                                >
                                                    {{ __('Call') }}
                                                </flux:button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </flux:card>
            @endif
        @endif
    </div>
</div>
