<?php

use App\Models\Place;
use App\Models\StockCheck;
use App\Models\StockCheckItem;
use App\Models\Thing;
use App\Services\HealthAidePinSession;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Stock Refill')] class extends Component
{
    public string $pin = '';

    public bool $showPinModal = true;

    public ?int $placeId = null;

    /** @var array<string, string|int|null> */
    public array $counts = [];

    public ?int $lastCheckId = null;

    public function mount(HealthAidePinSession $pinSession): void
    {
        $this->showPinModal = ! $pinSession->check();
    }

    /**
     * @return Collection<int, Place>
     */
    #[Computed]
    public function places(): Collection
    {
        return Place::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function selectedPlace(): ?Place
    {
        if ($this->placeId === null) {
            return null;
        }

        return Place::query()->active()->find($this->placeId);
    }

    /**
     * Active things assigned to the selected place.
     *
     * @return Collection<int, Thing>
     */
    #[Computed]
    public function placeThings(): Collection
    {
        $place = $this->selectedPlace;

        if ($place === null) {
            return new Collection;
        }

        return $place->activeThings()->orderBy('name')->get();
    }

    #[Computed]
    public function aideName(): ?string
    {
        return app(HealthAidePinSession::class)->current()?->name;
    }

    #[Computed]
    public function minutesRemaining(): ?int
    {
        return app(HealthAidePinSession::class)->minutesRemaining();
    }

    #[Computed]
    public function lastCheck(): ?StockCheck
    {
        if ($this->lastCheckId === null) {
            return null;
        }

        return StockCheck::query()
            ->with(['items.thing', 'place', 'healthAide'])
            ->find($this->lastCheckId);
    }

    /**
     * Refill items from the last saved check that need restocking.
     *
     * @return Collection<int, StockCheckItem>
     */
    #[Computed]
    public function refillList(): Collection
    {
        $check = $this->lastCheck;

        if ($check === null) {
            return new Collection;
        }

        return $check->items
            ->filter(fn (StockCheckItem $item) => $item->refill_needed > 0)
            ->values();
    }

    public function updatedPlaceId(): void
    {
        $this->counts = [];
        $this->lastCheckId = null;
        unset($this->selectedPlace, $this->placeThings, $this->lastCheck, $this->refillList);

        foreach ($this->placeThings as $thing) {
            $this->counts[(string) $thing->id] = null;
        }
    }

    public function verifyPin(HealthAidePinSession $pinSession): void
    {
        $this->validate([
            'pin' => ['required', 'digits_between:4,6'],
        ]);

        $aide = $pinSession->attempt($this->pin);

        if ($aide === null) {
            $this->addError('pin', __('Invalid PIN.'));

            return;
        }

        $this->pin = '';
        $this->showPinModal = false;
        $this->resetValidation();
        unset($this->aideName, $this->minutesRemaining);

        Flux::toast(variant: 'success', text: __('Unlocked as :name', ['name' => $aide->name]));
    }

    public function lock(HealthAidePinSession $pinSession): void
    {
        $pinSession->forget();
        $this->showPinModal = true;
        $this->pin = '';
        unset($this->aideName, $this->minutesRemaining);
        Flux::toast(text: __('Session locked.'));
    }

    public function needFor(int $thingId, int $stockPoint): int
    {
        $counted = $this->counts[(string) $thingId] ?? null;

        if ($counted === null || $counted === '') {
            return $stockPoint;
        }

        return max(0, $stockPoint - (int) $counted);
    }

    public function saveCheck(HealthAidePinSession $pinSession): void
    {
        $aide = $pinSession->current();

        if ($aide === null) {
            $this->showPinModal = true;
            Flux::toast(variant: 'danger', text: __('Enter your health aide PIN to continue.'));

            return;
        }

        $this->validate([
            'placeId' => ['required', 'integer', 'exists:places,id'],
            'counts' => ['required', 'array', 'min:1'],
            'counts.*' => ['required', 'integer', 'min:0'],
        ], [
            'counts.*.required' => __('Enter a count for each thing.'),
            'counts.*.integer' => __('Counts must be whole numbers.'),
            'counts.*.min' => __('Counts cannot be negative.'),
        ]);

        $place = Place::query()->active()->findOrFail($this->placeId);
        $things = $place->activeThings()->orderBy('name')->get();

        if ($things->isEmpty()) {
            Flux::toast(variant: 'danger', text: __('This place has no things to check.'));

            return;
        }

        $thingIds = $things->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($thingIds as $thingId) {
            if (! array_key_exists($thingId, $this->counts) || $this->counts[$thingId] === null || $this->counts[$thingId] === '') {
                $this->addError("counts.{$thingId}", __('Enter a count for each thing.'));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $check = DB::transaction(function () use ($place, $things, $aide) {
            $check = StockCheck::query()->create([
                'place_id' => $place->id,
                'health_aide_id' => $aide->id,
                'user_id' => auth()->id(),
                'checked_at' => now(),
            ]);

            foreach ($things as $thing) {
                $counted = (int) $this->counts[(string) $thing->id];
                $stockPoint = (int) $thing->pivot->stock_point;

                StockCheckItem::query()->create([
                    'stock_check_id' => $check->id,
                    'thing_id' => $thing->id,
                    'stock_point' => $stockPoint,
                    'counted_quantity' => $counted,
                    'refill_needed' => max(0, $stockPoint - $counted),
                ]);
            }

            return $check;
        });

        $this->lastCheckId = $check->id;
        unset($this->lastCheck, $this->refillList);

        Flux::toast(variant: 'success', text: __('Stock check saved.'));
    }

    public function startNewCheck(): void
    {
        $this->lastCheckId = null;
        $this->counts = [];
        unset($this->lastCheck, $this->refillList);

        foreach ($this->placeThings as $thing) {
            $this->counts[(string) $thing->id] = null;
        }
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading level="1">{{ __('Stock Refill') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('Select a place, enter how many of each thing are there, and see what to refill.') }}
            </flux:text>
        </div>

        @if (! $showPinModal && $this->aideName)
            <div class="flex items-center gap-3">
                <flux:text class="text-sm">
                    {{ $this->aideName }}
                    @if ($this->minutesRemaining !== null)
                        <span class="text-zinc-500">({{ __(':min min left', ['min' => $this->minutesRemaining]) }})</span>
                    @endif
                </flux:text>
                <flux:button size="sm" variant="ghost" wire:click="lock">
                    {{ __('Lock') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if (! $showPinModal)
        <flux:card>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Place') }}</flux:label>
                    <flux:select wire:model.live="placeId">
                        <option value="">{{ __('Select a place…') }}</option>
                        @foreach ($this->places as $place)
                            <option value="{{ $place->id }}">{{ $place->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="placeId" />
                </flux:field>
            </div>
        </flux:card>

        @if ($this->lastCheck && $this->lastCheck->place_id === $placeId)
            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Refilling list') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Saved :time by :aide', [
                                'time' => $this->lastCheck->checked_at?->format('g:i A'),
                                'aide' => $this->lastCheck->healthAide?->name ?? '—',
                            ]) }}
                        </flux:text>
                    </div>
                    <flux:button variant="ghost" wire:click="startNewCheck">
                        {{ __('New check') }}
                    </flux:button>
                </div>

                @if ($this->refillList->isEmpty())
                    <flux:text class="text-zinc-500">{{ __('Everything is at or above stock point. Nothing to refill.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Thing') }}</flux:table.column>
                            <flux:table.column>{{ __('Counted') }}</flux:table.column>
                            <flux:table.column>{{ __('Stock point') }}</flux:table.column>
                            <flux:table.column>{{ __('Need to refill') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->refillList as $item)
                                <flux:table.row wire:key="refill-{{ $item->id }}">
                                    <flux:table.cell class="font-medium">
                                        {{ $item->thing?->name ?? __('Unknown') }}
                                        @if ($item->thing?->unit)
                                            <span class="text-zinc-500">({{ $item->thing->unit }})</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $item->counted_quantity }}</flux:table.cell>
                                    <flux:table.cell>{{ $item->stock_point }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge color="amber">{{ $item->refill_needed }}</flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        @elseif ($placeId && $this->placeThings->isNotEmpty())
            <flux:card>
                <form wire:submit="saveCheck" class="space-y-4">
                    <flux:heading level="2" class="mb-2">{{ __('Current counts') }}</flux:heading>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Thing') }}</flux:table.column>
                            <flux:table.column>{{ __('Stock point') }}</flux:table.column>
                            <flux:table.column>{{ __('Count now') }}</flux:table.column>
                            <flux:table.column>{{ __('Need') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->placeThings as $thing)
                                @php
                                    $stockPoint = (int) $thing->pivot->stock_point;
                                    $need = $this->needFor($thing->id, $stockPoint);
                                @endphp
                                <flux:table.row wire:key="count-row-{{ $thing->id }}">
                                    <flux:table.cell class="font-medium">
                                        {{ $thing->name }}
                                        @if ($thing->unit)
                                            <span class="text-zinc-500">({{ $thing->unit }})</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $stockPoint }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:input
                                            type="number"
                                            min="0"
                                            wire:model.live="counts.{{ $thing->id }}"
                                            class="w-28"
                                        />
                                        <flux:error name="counts.{{ $thing->id }}" />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($need > 0)
                                            <flux:badge size="sm" color="amber">{{ $need }}</flux:badge>
                                        @else
                                            <span class="text-zinc-500">0</span>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">
                            {{ __('Save check & show refill list') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        @elseif ($placeId)
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('This place has no active things assigned yet. Ask an admin to configure it.') }}</flux:text>
            </flux:card>
        @endif
    @endif

    @if ($showPinModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/80 p-4">
            <div class="w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <flux:heading level="2" size="lg" class="text-center">
                    {{ __('Enter PIN') }}
                </flux:heading>
                <flux:text class="mt-2 text-center text-zinc-500">
                    {{ __('Enter your health aide PIN to continue. Session lasts 10 minutes.') }}
                </flux:text>

                <form wire:submit="verifyPin" class="mt-6 space-y-4">
                    <flux:input
                        type="password"
                        wire:model="pin"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="----"
                        class="text-center text-2xl tracking-[0.5em]"
                        autofocus
                    />
                    @error('pin')
                        <flux:text variant="danger" class="text-center">{{ $message }}</flux:text>
                    @enderror
                    <flux:button type="submit" variant="primary" class="w-full">
                        {{ __('Unlock') }}
                    </flux:button>
                </form>
            </div>
        </div>
    @endif
</div>
