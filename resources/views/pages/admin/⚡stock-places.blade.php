<?php

use App\Models\Place;
use App\Models\StockCheck;
use App\Models\Thing;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Stock Places')] class extends Component
{
    use WithPagination;

    public string $activeTab = 'places';

    public bool $showPlaceModal = false;

    public ?int $editingPlaceId = null;

    public string $placeName = '';

    public bool $placeIsActive = true;

    public bool $showThingModal = false;

    public ?int $editingThingId = null;

    public string $thingName = '';

    public string $thingUnit = '';

    public bool $thingIsActive = true;

    public ?int $assignPlaceId = null;

    public ?int $assignThingId = null;

    public int $assignStockPoint = 1;

    public bool $showAssignModal = false;

    public ?int $editingAssignmentThingId = null;

    public int $editStockPoint = 1;

    public bool $editAssignmentIsActive = true;

    public ?int $viewingCheckId = null;

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
        $this->viewingCheckId = null;
    }

    /**
     * @return Collection<int, Place>
     */
    #[Computed]
    public function places(): Collection
    {
        return Place::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Thing>
     */
    #[Computed]
    public function things(): Collection
    {
        return Thing::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Thing>
     */
    #[Computed]
    public function activeThings(): Collection
    {
        return Thing::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function assignPlace(): ?Place
    {
        if ($this->assignPlaceId === null) {
            return null;
        }

        return Place::query()
            ->with(['things' => fn ($query) => $query->orderBy('name')])
            ->find($this->assignPlaceId);
    }

    /**
     * @return LengthAwarePaginator<int, StockCheck>
     */
    #[Computed]
    public function stockChecks(): LengthAwarePaginator
    {
        return StockCheck::query()
            ->with(['place', 'healthAide', 'user'])
            ->latest('checked_at')
            ->paginate(15);
    }

    #[Computed]
    public function viewingCheck(): ?StockCheck
    {
        if ($this->viewingCheckId === null) {
            return null;
        }

        return StockCheck::query()
            ->with(['place', 'healthAide', 'user', 'items.thing'])
            ->find($this->viewingCheckId);
    }

    public function openCreatePlaceModal(): void
    {
        $this->resetPlaceForm();
        $this->showPlaceModal = true;
    }

    public function editPlace(int $id): void
    {
        $place = Place::query()->findOrFail($id);

        $this->editingPlaceId = $place->id;
        $this->placeName = $place->name;
        $this->placeIsActive = $place->is_active;
        $this->resetValidation();
        $this->showPlaceModal = true;
    }

    public function savePlace(): void
    {
        $validated = $this->validate([
            'placeName' => ['required', 'string', 'max:255'],
            'placeIsActive' => ['required', 'boolean'],
        ]);

        $attributes = [
            'name' => $validated['placeName'],
            'is_active' => $validated['placeIsActive'],
        ];

        if ($this->editingPlaceId !== null) {
            Place::query()->findOrFail($this->editingPlaceId)->update($attributes);
            Flux::toast(variant: 'success', text: __('Place updated.'));
        } else {
            Place::query()->create($attributes);
            Flux::toast(variant: 'success', text: __('Place created.'));
        }

        $this->showPlaceModal = false;
        $this->resetPlaceForm();
        unset($this->places);
    }

    public function togglePlaceActive(int $id): void
    {
        $place = Place::query()->findOrFail($id);
        $place->update(['is_active' => ! $place->is_active]);
        unset($this->places);

        Flux::toast(
            variant: 'success',
            text: $place->is_active ? __('Place activated.') : __('Place deactivated.'),
        );
    }

    public function openCreateThingModal(): void
    {
        $this->resetThingForm();
        $this->showThingModal = true;
    }

    public function editThing(int $id): void
    {
        $thing = Thing::query()->findOrFail($id);

        $this->editingThingId = $thing->id;
        $this->thingName = $thing->name;
        $this->thingUnit = $thing->unit ?? '';
        $this->thingIsActive = $thing->is_active;
        $this->resetValidation();
        $this->showThingModal = true;
    }

    public function saveThing(): void
    {
        $validated = $this->validate([
            'thingName' => ['required', 'string', 'max:255'],
            'thingUnit' => ['nullable', 'string', 'max:50'],
            'thingIsActive' => ['required', 'boolean'],
        ]);

        $attributes = [
            'name' => $validated['thingName'],
            'unit' => filled($validated['thingUnit']) ? $validated['thingUnit'] : null,
            'is_active' => $validated['thingIsActive'],
        ];

        if ($this->editingThingId !== null) {
            Thing::query()->findOrFail($this->editingThingId)->update($attributes);
            Flux::toast(variant: 'success', text: __('Thing updated.'));
        } else {
            Thing::query()->create($attributes);
            Flux::toast(variant: 'success', text: __('Thing created.'));
        }

        $this->showThingModal = false;
        $this->resetThingForm();
        unset($this->things, $this->activeThings, $this->assignPlace);
    }

    public function toggleThingActive(int $id): void
    {
        $thing = Thing::query()->findOrFail($id);
        $thing->update(['is_active' => ! $thing->is_active]);
        unset($this->things, $this->activeThings, $this->assignPlace);

        Flux::toast(
            variant: 'success',
            text: $thing->is_active ? __('Thing activated.') : __('Thing deactivated.'),
        );
    }

    public function updatedAssignPlaceId(): void
    {
        unset($this->assignPlace);
    }

    public function openAssignModal(): void
    {
        if ($this->assignPlaceId === null) {
            Flux::toast(variant: 'danger', text: __('Select a place first.'));

            return;
        }

        $this->assignThingId = null;
        $this->assignStockPoint = 1;
        $this->resetValidation();
        $this->showAssignModal = true;
    }

    public function assignThing(): void
    {
        $validated = $this->validate([
            'assignPlaceId' => ['required', 'integer', 'exists:places,id'],
            'assignThingId' => ['required', 'integer', 'exists:things,id'],
            'assignStockPoint' => ['required', 'integer', 'min:0'],
        ]);

        $place = Place::query()->findOrFail($validated['assignPlaceId']);

        if ($place->things()->where('things.id', $validated['assignThingId'])->exists()) {
            $this->addError('assignThingId', __('This thing is already assigned to this place.'));

            return;
        }

        $place->things()->attach($validated['assignThingId'], [
            'stock_point' => $validated['assignStockPoint'],
            'is_active' => true,
        ]);

        Flux::toast(variant: 'success', text: __('Thing assigned to place.'));
        $this->showAssignModal = false;
        $this->assignThingId = null;
        $this->assignStockPoint = 1;
        unset($this->assignPlace);
    }

    public function editAssignment(int $thingId): void
    {
        $place = $this->assignPlace;

        if ($place === null) {
            return;
        }

        $thing = $place->things->firstWhere('id', $thingId);

        if ($thing === null) {
            return;
        }

        $this->editingAssignmentThingId = $thingId;
        $this->editStockPoint = (int) $thing->pivot->stock_point;
        $this->editAssignmentIsActive = (bool) $thing->pivot->is_active;
        $this->resetValidation();
    }

    public function saveAssignment(): void
    {
        $validated = $this->validate([
            'assignPlaceId' => ['required', 'integer', 'exists:places,id'],
            'editingAssignmentThingId' => ['required', 'integer', 'exists:things,id'],
            'editStockPoint' => ['required', 'integer', 'min:0'],
            'editAssignmentIsActive' => ['required', 'boolean'],
        ]);

        $place = Place::query()->findOrFail($validated['assignPlaceId']);

        $place->things()->updateExistingPivot($validated['editingAssignmentThingId'], [
            'stock_point' => $validated['editStockPoint'],
            'is_active' => $validated['editAssignmentIsActive'],
        ]);

        Flux::toast(variant: 'success', text: __('Assignment updated.'));
        $this->editingAssignmentThingId = null;
        unset($this->assignPlace);
    }

    public function removeAssignment(int $thingId): void
    {
        if ($this->assignPlaceId === null) {
            return;
        }

        Place::query()->findOrFail($this->assignPlaceId)->things()->detach($thingId);
        Flux::toast(variant: 'success', text: __('Thing removed from place.'));
        unset($this->assignPlace);
    }

    public function viewCheck(int $id): void
    {
        $this->viewingCheckId = $id;
        unset($this->viewingCheck);
    }

    public function closeCheckDetail(): void
    {
        $this->viewingCheckId = null;
        unset($this->viewingCheck);
    }

    private function resetPlaceForm(): void
    {
        $this->editingPlaceId = null;
        $this->placeName = '';
        $this->placeIsActive = true;
        $this->resetValidation();
    }

    private function resetThingForm(): void
    {
        $this->editingThingId = null;
        $this->thingName = '';
        $this->thingUnit = '';
        $this->thingIsActive = true;
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Stock Places') }}</flux:heading>
        <flux:text class="text-zinc-500">
            {{ __('Configure places, things, stock points, and review refill check history.') }}
        </flux:text>
    </div>

    <div class="flex gap-4 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
        @foreach ([
            'places' => __('Places'),
            'things' => __('Things'),
            'assign' => __('Assign'),
            'history' => __('History'),
        ] as $tab => $label)
            <button
                type="button"
                wire:click="setTab('{{ $tab }}')"
                class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === $tab ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($activeTab === 'places')
        <flux:card>
            <div class="mb-4 flex items-center justify-between gap-4">
                <flux:text>{{ __('Storage places staff can check for refills.') }}</flux:text>
                <flux:button variant="primary" icon="plus" wire:click="openCreatePlaceModal">
                    {{ __('Add place') }}
                </flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->places as $place)
                        <flux:table.row wire:key="place-{{ $place->id }}">
                            <flux:table.cell class="font-medium">{{ $place->name }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$place->is_active ? 'green' : 'zinc'">
                                    {{ $place->is_active ? __('Active') : __('Inactive') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="editPlace({{ $place->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="togglePlaceActive({{ $place->id }})">
                                        {{ $place->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="text-center text-zinc-500">
                                {{ __('No places yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    @if ($activeTab === 'things')
        <flux:card>
            <div class="mb-4 flex items-center justify-between gap-4">
                <flux:text>{{ __('Shared catalog of things that can be stocked at places.') }}</flux:text>
                <flux:button variant="primary" icon="plus" wire:click="openCreateThingModal">
                    {{ __('Add thing') }}
                </flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Unit') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->things as $thing)
                        <flux:table.row wire:key="thing-{{ $thing->id }}">
                            <flux:table.cell class="font-medium">{{ $thing->name }}</flux:table.cell>
                            <flux:table.cell>{{ $thing->unit ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$thing->is_active ? 'green' : 'zinc'">
                                    {{ $thing->is_active ? __('Active') : __('Inactive') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="editThing({{ $thing->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="toggleThingActive({{ $thing->id }})">
                                        {{ $thing->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                {{ __('No things yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    @if ($activeTab === 'assign')
        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <flux:field class="w-full sm:max-w-xs">
                    <flux:label>{{ __('Place') }}</flux:label>
                    <flux:select wire:model.live="assignPlaceId">
                        <option value="">{{ __('Select a place…') }}</option>
                        @foreach ($this->places as $place)
                            <option value="{{ $place->id }}">{{ $place->name }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
                <flux:button
                    variant="primary"
                    icon="plus"
                    wire:click="openAssignModal"
                    :disabled="$assignPlaceId === null"
                >
                    {{ __('Assign thing') }}
                </flux:button>
            </div>

            @if ($this->assignPlace)
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Thing') }}</flux:table.column>
                        <flux:table.column>{{ __('Stock point') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->assignPlace->things as $thing)
                            <flux:table.row wire:key="assignment-{{ $thing->id }}">
                                <flux:table.cell class="font-medium">
                                    {{ $thing->name }}
                                    @if ($thing->unit)
                                        <span class="text-zinc-500">({{ $thing->unit }})</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($editingAssignmentThingId === $thing->id)
                                        <flux:input type="number" min="0" wire:model="editStockPoint" class="w-24" />
                                        <flux:error name="editStockPoint" />
                                    @else
                                        {{ $thing->pivot->stock_point }}
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($editingAssignmentThingId === $thing->id)
                                        <flux:switch wire:model="editAssignmentIsActive" />
                                    @else
                                        <flux:badge size="sm" :color="$thing->pivot->is_active ? 'green' : 'zinc'">
                                            {{ $thing->pivot->is_active ? __('Active') : __('Inactive') }}
                                        </flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex gap-2">
                                        @if ($editingAssignmentThingId === $thing->id)
                                            <flux:button size="sm" variant="primary" wire:click="saveAssignment">
                                                {{ __('Save') }}
                                            </flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="$set('editingAssignmentThingId', null)">
                                                {{ __('Cancel') }}
                                            </flux:button>
                                        @else
                                            <flux:button size="sm" variant="ghost" wire:click="editAssignment({{ $thing->id }})">
                                                {{ __('Edit') }}
                                            </flux:button>
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                wire:click="removeAssignment({{ $thing->id }})"
                                                wire:confirm="{{ __('Remove this thing from the place?') }}"
                                            >
                                                {{ __('Remove') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                    {{ __('No things assigned to this place yet.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            @else
                <flux:text class="text-zinc-500">{{ __('Select a place to manage its things and stock points.') }}</flux:text>
            @endif
        </flux:card>
    @endif

    @if ($activeTab === 'history')
        @if ($this->viewingCheck)
            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ $this->viewingCheck->place?->name }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ $this->viewingCheck->checked_at?->format('M j, Y g:i A') }}
                            · {{ $this->viewingCheck->healthAide?->name ?? '—' }}
                            @if ($this->viewingCheck->user)
                                · {{ __('Logged in as :name', ['name' => $this->viewingCheck->user->name]) }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:button variant="ghost" wire:click="closeCheckDetail">
                        {{ __('Back to list') }}
                    </flux:button>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Thing') }}</flux:table.column>
                        <flux:table.column>{{ __('Stock point') }}</flux:table.column>
                        <flux:table.column>{{ __('Counted') }}</flux:table.column>
                        <flux:table.column>{{ __('Need') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->viewingCheck->items as $item)
                            <flux:table.row wire:key="check-item-{{ $item->id }}">
                                <flux:table.cell class="font-medium">
                                    {{ $item->thing?->name ?? __('Unknown') }}
                                    @if ($item->thing?->unit)
                                        <span class="text-zinc-500">({{ $item->thing->unit }})</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $item->stock_point }}</flux:table.cell>
                                <flux:table.cell>{{ $item->counted_quantity }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($item->refill_needed > 0)
                                        <flux:badge size="sm" color="amber">{{ $item->refill_needed }}</flux:badge>
                                    @else
                                        <span class="text-zinc-500">0</span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @else
            <flux:card>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('When') }}</flux:table.column>
                        <flux:table.column>{{ __('Place') }}</flux:table.column>
                        <flux:table.column>{{ __('Health aide') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->stockChecks as $check)
                            <flux:table.row wire:key="stock-check-{{ $check->id }}">
                                <flux:table.cell>{{ $check->checked_at?->format('M j, Y g:i A') }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $check->place?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $check->healthAide?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $check->user?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="sm" variant="ghost" wire:click="viewCheck({{ $check->id }})">
                                        {{ __('View') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                    {{ __('No stock checks yet.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>

                <div class="mt-4">
                    {{ $this->stockChecks->links() }}
                </div>
            </flux:card>
        @endif
    @endif

    <flux:modal wire:model="showPlaceModal" class="max-w-md">
        <form wire:submit="savePlace" class="space-y-4">
            <flux:heading size="lg">
                {{ $editingPlaceId ? __('Edit place') : __('Add place') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="placeName" />
                <flux:error name="placeName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Active') }}</flux:label>
                <flux:switch wire:model="placeIsActive" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showPlaceModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showThingModal" class="max-w-md">
        <form wire:submit="saveThing" class="space-y-4">
            <flux:heading size="lg">
                {{ $editingThingId ? __('Edit thing') : __('Add thing') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="thingName" />
                <flux:error name="thingName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Unit') }}</flux:label>
                <flux:input wire:model="thingUnit" placeholder="{{ __('e.g. box, pack') }}" />
                <flux:error name="thingUnit" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Active') }}</flux:label>
                <flux:switch wire:model="thingIsActive" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showThingModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showAssignModal" class="max-w-md">
        <form wire:submit="assignThing" class="space-y-4">
            <flux:heading size="lg">{{ __('Assign thing') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Thing') }}</flux:label>
                <flux:select wire:model="assignThingId">
                    <option value="">{{ __('Select…') }}</option>
                    @foreach ($this->activeThings as $thing)
                        <option value="{{ $thing->id }}">
                            {{ $thing->name }}{{ $thing->unit ? " ({$thing->unit})" : '' }}
                        </option>
                    @endforeach
                </flux:select>
                <flux:error name="assignThingId" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Stock point') }}</flux:label>
                <flux:input type="number" min="0" wire:model="assignStockPoint" />
                <flux:description>{{ __('Target quantity to keep at this place.') }}</flux:description>
                <flux:error name="assignStockPoint" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showAssignModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">{{ __('Assign') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
