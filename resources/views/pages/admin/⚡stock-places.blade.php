<?php

use App\Models\Place;
use App\Models\StockCheck;
use App\Models\Thing;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Stock Places')] class extends Component
{
    use WithPagination;

    private const BULK_ROWS = 10;

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

    public bool $showBulkModal = false;

    public ?int $bulkPlaceId = null;

    /**
     * @var list<array{name: string, unit: string, stock_point: string}>
     */
    public array $bulkRows = [];

    public bool $showAssignModal = false;

    public ?int $assignThingId = null;

    public ?int $assignPlaceId = null;

    public int $assignStockPoint = 1;

    public ?int $viewingCheckId = null;

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }

        $this->bulkRows = $this->emptyBulkRows();
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
     * @return Collection<int, Place>
     */
    #[Computed]
    public function activePlaces(): Collection
    {
        return Place::query()->active()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Thing>
     */
    #[Computed]
    public function things(): Collection
    {
        return Thing::query()
            ->with(['places' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();
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
        unset($this->places, $this->activePlaces);
    }

    public function togglePlaceActive(int $id): void
    {
        $place = Place::query()->findOrFail($id);
        $place->update(['is_active' => ! $place->is_active]);
        unset($this->places, $this->activePlaces);

        Flux::toast(
            variant: 'success',
            text: $place->is_active ? __('Place activated.') : __('Place deactivated.'),
        );
    }

    public function openBulkModal(): void
    {
        $this->bulkPlaceId = null;
        $this->bulkRows = $this->emptyBulkRows();
        $this->resetValidation();
        $this->showBulkModal = true;
    }

    public function saveBulkThings(): void
    {
        $validated = $this->validate([
            'bulkPlaceId' => ['required', 'integer', 'exists:places,id'],
            'bulkRows' => ['required', 'array', 'size:'.self::BULK_ROWS],
            'bulkRows.*.name' => ['nullable', 'string', 'max:255'],
            'bulkRows.*.unit' => ['nullable', 'string', 'max:50'],
            'bulkRows.*.stock_point' => ['nullable', 'integer', 'min:0'],
        ]);

        $rows = collect($validated['bulkRows'])
            ->filter(fn (array $row): bool => filled($row['name'] ?? null))
            ->values();

        if ($rows->isEmpty()) {
            $this->addError('bulkRows', __('Enter at least one thing name.'));

            return;
        }

        foreach ($rows as $index => $row) {
            if (! array_key_exists('stock_point', $row) || $row['stock_point'] === null || $row['stock_point'] === '') {
                $this->addError("bulkRows.{$index}.stock_point", __('Stock point is required when a name is entered.'));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $place = Place::query()->findOrFail($validated['bulkPlaceId']);

        $created = DB::transaction(function () use ($rows, $place): int {
            $count = 0;

            foreach ($rows as $row) {
                $thing = Thing::query()->create([
                    'name' => $row['name'],
                    'unit' => filled($row['unit'] ?? null) ? $row['unit'] : null,
                    'is_active' => true,
                ]);

                $place->things()->attach($thing->id, [
                    'stock_point' => (int) $row['stock_point'],
                    'is_active' => true,
                ]);

                $count++;
            }

            return $count;
        });

        Flux::toast(
            variant: 'success',
            text: __(':count things added and assigned to :place.', [
                'count' => $created,
                'place' => $place->name,
            ]),
        );

        $this->showBulkModal = false;
        $this->bulkPlaceId = null;
        $this->bulkRows = $this->emptyBulkRows();
        unset($this->things);
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

        Thing::query()->findOrFail($this->editingThingId)->update([
            'name' => $validated['thingName'],
            'unit' => filled($validated['thingUnit']) ? $validated['thingUnit'] : null,
            'is_active' => $validated['thingIsActive'],
        ]);

        Flux::toast(variant: 'success', text: __('Thing updated.'));
        $this->showThingModal = false;
        $this->resetThingForm();
        unset($this->things);
    }

    public function toggleThingActive(int $id): void
    {
        $thing = Thing::query()->findOrFail($id);
        $thing->update(['is_active' => ! $thing->is_active]);
        unset($this->things);

        Flux::toast(
            variant: 'success',
            text: $thing->is_active ? __('Thing activated.') : __('Thing deactivated.'),
        );
    }

    public function openAssignModal(int $thingId): void
    {
        $thing = Thing::query()->findOrFail($thingId);

        $this->assignThingId = $thing->id;
        $this->assignPlaceId = null;
        $this->assignStockPoint = 1;
        $this->resetValidation();
        $this->showAssignModal = true;
    }

    public function assignThingToPlace(): void
    {
        $validated = $this->validate([
            'assignThingId' => ['required', 'integer', 'exists:things,id'],
            'assignPlaceId' => ['required', 'integer', 'exists:places,id'],
            'assignStockPoint' => ['required', 'integer', 'min:0'],
        ]);

        $place = Place::query()->findOrFail($validated['assignPlaceId']);
        $thingId = $validated['assignThingId'];

        if ($place->things()->where('things.id', $thingId)->exists()) {
            $place->things()->updateExistingPivot($thingId, [
                'stock_point' => $validated['assignStockPoint'],
                'is_active' => true,
            ]);

            Flux::toast(variant: 'success', text: __('Assignment updated.'));
        } else {
            $place->things()->attach($thingId, [
                'stock_point' => $validated['assignStockPoint'],
                'is_active' => true,
            ]);

            Flux::toast(variant: 'success', text: __('Thing assigned to place.'));
        }

        $this->showAssignModal = false;
        $this->assignThingId = null;
        $this->assignPlaceId = null;
        $this->assignStockPoint = 1;
        unset($this->things);
    }

    public function removeAssignment(int $thingId, int $placeId): void
    {
        Place::query()->findOrFail($placeId)->things()->detach($thingId);
        Flux::toast(variant: 'success', text: __('Thing removed from place.'));
        unset($this->things);
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

    /**
     * @return list<array{name: string, unit: string, stock_point: string}>
     */
    private function emptyBulkRows(): array
    {
        return array_map(
            fn (): array => ['name' => '', 'unit' => '', 'stock_point' => ''],
            range(1, self::BULK_ROWS),
        );
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
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:text>{{ __('Add things in bulk to a place, or assign existing things from the list.') }}</flux:text>
                <flux:button variant="primary" icon="plus" wire:click="openBulkModal">
                    {{ __('Bulk add (10)') }}
                </flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Unit') }}</flux:table.column>
                    <flux:table.column>{{ __('Assigned places') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->things as $thing)
                        <flux:table.row wire:key="thing-{{ $thing->id }}">
                            <flux:table.cell class="font-medium">{{ $thing->name }}</flux:table.cell>
                            <flux:table.cell>{{ $thing->unit ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($thing->places->isEmpty())
                                    <span class="text-zinc-500">{{ __('None') }}</span>
                                @else
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($thing->places as $place)
                                            <flux:badge size="sm" color="zinc" class="gap-1">
                                                {{ $place->name }}: {{ $place->pivot->stock_point }}
                                                <button
                                                    type="button"
                                                    class="ms-1 text-zinc-500 hover:text-red-600"
                                                    wire:click="removeAssignment({{ $thing->id }}, {{ $place->id }})"
                                                    wire:confirm="{{ __('Remove :thing from :place?', ['thing' => $thing->name, 'place' => $place->name]) }}"
                                                    title="{{ __('Remove') }}"
                                                >
                                                    ×
                                                </button>
                                            </flux:badge>
                                        @endforeach
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$thing->is_active ? 'green' : 'zinc'">
                                    {{ $thing->is_active ? __('Active') : __('Inactive') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="openAssignModal({{ $thing->id }})">
                                        {{ __('Assign') }}
                                    </flux:button>
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
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No things yet. Use Bulk add to create and assign them.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
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

    <flux:modal wire:model="showBulkModal" class="max-w-4xl">
        <form wire:submit="saveBulkThings" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Bulk add things') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Choose the place once, then fill up to 10 things with stock points. Empty name rows are skipped.') }}
                </flux:text>
            </div>

            <flux:field class="max-w-sm">
                <flux:label>{{ __('Assign to place') }}</flux:label>
                <flux:select wire:model="bulkPlaceId">
                    <option value="">{{ __('Select a place…') }}</option>
                    @foreach ($this->activePlaces as $place)
                        <option value="{{ $place->id }}">{{ $place->name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="bulkPlaceId" />
            </flux:field>

            <div class="space-y-2">
                <div class="hidden gap-2 px-3 text-xs font-medium text-zinc-500 sm:grid sm:grid-cols-12">
                    <div class="sm:col-span-5">{{ __('Name') }}</div>
                    <div class="sm:col-span-3">{{ __('Unit') }}</div>
                    <div class="sm:col-span-4">{{ __('Stock point') }}</div>
                </div>

                @foreach ($bulkRows as $index => $row)
                    <div wire:key="bulk-row-{{ $index }}" class="grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12">
                        <div class="sm:col-span-5">
                            <flux:input wire:model="bulkRows.{{ $index }}.name" placeholder="{{ __('Name') }}" />
                            <flux:error name="bulkRows.{{ $index }}.name" />
                        </div>
                        <div class="sm:col-span-3">
                            <flux:input wire:model="bulkRows.{{ $index }}.unit" placeholder="{{ __('Unit') }}" />
                            <flux:error name="bulkRows.{{ $index }}.unit" />
                        </div>
                        <div class="sm:col-span-4">
                            <flux:input type="number" min="0" wire:model="bulkRows.{{ $index }}.stock_point" placeholder="{{ __('Stock point') }}" />
                            <flux:error name="bulkRows.{{ $index }}.stock_point" />
                        </div>
                    </div>
                @endforeach
                <flux:error name="bulkRows" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showBulkModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save all') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showThingModal" class="max-w-md">
        <form wire:submit="saveThing" class="space-y-4">
            <flux:heading size="lg">{{ __('Edit thing') }}</flux:heading>

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
        <form wire:submit="assignThingToPlace" class="space-y-4">
            <flux:heading size="lg">{{ __('Assign to place') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('If already assigned, the stock point will be updated.') }}
            </flux:text>

            <flux:field>
                <flux:label>{{ __('Place') }}</flux:label>
                <flux:select wire:model="assignPlaceId">
                    <option value="">{{ __('Select…') }}</option>
                    @foreach ($this->activePlaces as $place)
                        <option value="{{ $place->id }}">{{ $place->name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="assignPlaceId" />
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
