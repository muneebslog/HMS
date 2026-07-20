<?php

use App\Models\Doctor;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Token Flow')] class extends Component
{
    public ?int $selectedDoctorId = null;

    public string $selectedDate = '';

    public string $sortColumn = 'token_number';

    public string $sortDirection = 'asc';

    /**
     * Initialize the component state.
     */
    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
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
     * Get the queue IDs for the selected doctor, consultation service, and date.
     *
     * @return SupportCollection<int, int>
     */
    #[Computed]
    public function queueIds(): SupportCollection
    {
        $service = $this->consultationService;

        if ($service === null || $this->selectedDoctorId === null || blank($this->selectedDate)) {
            return collect();
        }

        return ServiceQueue::where('service_id', $service->id)
            ->where('doctor_id', $this->selectedDoctorId)
            ->whereDate('date', Carbon::parse($this->selectedDate))
            ->pluck('id');
    }

    /**
     * Sort the table by the given column in ascending order.
     */
    public function sortBy(string $column): void
    {
        $this->sortColumn = $column;
        $this->sortDirection = 'asc';
    }

    /**
     * Get the tokens for the selected doctor and date with flow timestamps.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        $queueIds = $this->queueIds;

        if ($queueIds->isEmpty()) {
            return new Collection();
        }

        return QueueToken::with(['patient', 'serviceQueue.doctor', 'invoiceItem.invoice.patient'])
            ->whereIn('service_queue_id', $queueIds)
            ->orderBy($this->sortColumn, $this->sortDirection)
            ->orderBy('token_number')
            ->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Token Flow') }}</flux:heading>
        </div>

        @if ($this->consultationService === null)
            <flux:card>
                <flux:heading level="2">{{ __('Consultation Service Missing') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Please create a service named "consultation" in Management first.') }}
                </flux:text>
            </flux:card>
        @else
            <flux:card>
                <div class="grid grid-cols-1 items-end gap-6 md:grid-cols-3">
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

                    <flux:field>
                        <flux:label>{{ __('Date') }}</flux:label>
                        <flux:input wire:model.live="selectedDate" type="date" />
                    </flux:field>
                </div>
            </flux:card>

            @if ($this->selectedDoctorId)
                <flux:card>
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="2">{{ __('Token Timeline') }}</flux:heading>
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ $this->tokens->count() }}</flux:text>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <th class="cursor-pointer py-3 pr-4 font-semibold" wire:click="sortBy('token_number')">
                                        <span class="flex items-center gap-1">
                                            {{ __('Token #') }}
                                            @if ($this->sortColumn === 'token_number')
                                                <flux:icon name="chevron-up" class="h-4 w-4" />
                                            @endif
                                        </span>
                                    </th>
                                    <th class="py-3 pr-4 font-semibold">{{ __('Patient') }}</th>
                                    <th class="py-3 pr-4 font-semibold">{{ __('Origin') }}</th>
                                    <th class="py-3 pr-4 font-semibold">{{ __('Reserved At') }}</th>
                                    <th class="cursor-pointer py-3 pr-4 font-semibold" wire:click="sortBy('arrived_at')">
                                        <span class="flex items-center gap-1">
                                            {{ __('Arrived At') }}
                                            @if ($this->sortColumn === 'arrived_at')
                                                <flux:icon name="chevron-up" class="h-4 w-4" />
                                            @endif
                                        </span>
                                    </th>
                                    <th class="py-3 pr-4 font-semibold">{{ __('Served / On TV At') }}</th>
                                    <th class="py-3 font-semibold">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @forelse ($this->tokens as $token)
                                    <tr wire:key="token-flow-{{ $token->id }}">
                                        <td class="py-3 pr-4 font-semibold">{{ $token->token_number }}</td>
                                        <td class="py-3 pr-4">
                                            {{ $token->patient?->name ?? $token->invoiceItem?->invoice?->patient?->name ?? '-' }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            @if ($token->arrived_at === null || $token->created_at->diffInMinutes($token->arrived_at) > 1)
                                                <flux:badge size="sm" color="purple">{{ __('Reservation') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="zinc">{{ __('Walk-in') }}</flux:badge>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-4">
                                            {{ $token->created_at->format('Y-m-d H:i') }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            {{ $token->arrived_at?->format('Y-m-d H:i') ?? '-' }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            {{ $token->displayed_at?->format('Y-m-d H:i') ?? '-' }}
                                        </td>
                                        <td class="py-3">
                                            @if ($token->status === 'reserved')
                                                <flux:badge size="sm" color="purple">{{ __('Reserved') }}</flux:badge>
                                            @elseif ($token->status === 'waiting')
                                                <flux:badge size="sm" color="amber">{{ __('Waiting') }}</flux:badge>
                                            @elseif ($token->status === 'serving')
                                                <flux:badge size="sm" color="blue">{{ __('Serving') }}</flux:badge>
                                            @elseif ($token->status === 'served')
                                                <flux:badge size="sm" color="green">{{ __('Served') }}</flux:badge>
                                            @elseif ($token->status === 'skipped')
                                                <flux:badge size="sm" color="zinc">{{ __('Skipped') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm">{{ ucfirst($token->status) }}</flux:badge>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-6 text-center text-zinc-500">
                                            {{ __('No tokens found for this doctor on the selected date.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </flux:card>
            @endif
        @endif
    </div>
</div>
