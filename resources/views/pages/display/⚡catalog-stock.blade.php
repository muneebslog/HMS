<?php

use App\Enums\StockLocation;
use App\Enums\StockMovementReason;
use App\Enums\StationType;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\Supply;
use App\Services\HealthAidePinSession;
use App\Services\InventoryStockService;
use App\Services\StationSessionService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Catalog Stock')] class extends Component
{
    public string $pin = '';

    public bool $showPinModal = false;

    public string $search = '';

    public string $activeMode = 'back';

    public string $activeTab = 'medicines';

    /**
     * @var array<int|string, string>
     */
    public array $quantities = [];

    public function mount(HealthAidePinSession $pinSession): void
    {
        if (! $pinSession->check()) {
            $this->showPinModal = true;

            return;
        }

        $this->hydrateQuantityForms();
    }

    /**
     * @return Collection<int, Medicine>
     */
    #[Computed]
    public function medicines(): Collection
    {
        return Medicine::query()
            ->active()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('short_form', 'like', $term);
                });
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Injection>
     */
    #[Computed]
    public function injections(): Collection
    {
        return Injection::query()
            ->active()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('short_form', 'like', $term);
                });
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, DripBase>
     */
    #[Computed]
    public function dripBases(): Collection
    {
        return DripBase::query()
            ->active()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where('name', 'like', $term);
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Supply>
     */
    #[Computed]
    public function supplies(): Collection
    {
        return Supply::query()
            ->active()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('short_form', 'like', $term)
                        ->orWhere('category', 'like', $term);
                });
            })
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function currentAideName(): ?string
    {
        return app(HealthAidePinSession::class)->current()?->name;
    }

    public function updatedSearch(): void
    {
        unset($this->medicines, $this->injections, $this->dripBases, $this->supplies);
    }

    public function switchMode(string $mode): void
    {
        if (! in_array($mode, ['back', 'issue', 'replenish', 'front'], true)) {
            return;
        }

        $this->activeMode = $mode;
        $this->hydrateQuantityForms();
    }

    public function switchTab(string $tab): void
    {
        if (! in_array($tab, ['medicines', 'injections', 'dripBases', 'supplies'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->hydrateQuantityForms();
    }

    public function verifyPin(HealthAidePinSession $pinSession, StationSessionService $stationSessions): void
    {
        $this->validate([
            'pin' => ['required', 'digits_between:4,6'],
        ]);

        $aide = $pinSession->attempt($this->pin);

        if ($aide === null) {
            $this->addError('pin', __('Invalid PIN.'));

            return;
        }

        $stationSessions->touch(StationType::Stock, $aide);

        $this->pin = '';
        $this->showPinModal = false;
        $this->resetValidation();
        $this->hydrateQuantityForms();

        Flux::toast(variant: 'success', text: __('Unlocked as :name', ['name' => $aide->name]));
    }

    public function lock(HealthAidePinSession $pinSession, StationSessionService $stationSessions): void
    {
        $pinSession->forget();
        $stationSessions->clear(StationType::Stock);
        $this->showPinModal = true;
        $this->quantities = [];
        Flux::toast(text: __('Session locked.'));
    }

    public function receiveStock(string $type, int $id): void
    {
        $this->performStockAction('receive', $type, $id);
    }

    public function adjustBackStock(string $type, int $id): void
    {
        $this->performStockAction('adjust', $type, $id);
    }

    public function issueToFront(string $type, int $id): void
    {
        $this->performStockAction('issue', $type, $id);
    }

    public function replenishFront(string $type, int $id): void
    {
        $this->performStockAction('replenish', $type, $id);
    }

    public function useFromFront(string $type, int $id): void
    {
        $this->performStockAction('use', $type, $id);
    }

    private function performStockAction(string $action, string $type, int $id): void
    {
        $aide = app(HealthAidePinSession::class)->current();

        if ($aide === null) {
            $this->showPinModal = true;
            Flux::toast(variant: 'danger', text: __('Enter your PIN to continue.'));

            return;
        }

        $field = "quantities.{$type}.{$id}";

        $this->validate([
            $field => ['required', 'integer', 'min:0'],
        ]);

        $quantity = (int) $this->quantities[$type][$id];
        $stockable = $this->resolveStockable($type, $id);
        $stock = app(InventoryStockService::class);

        try {
            match ($action) {
                'receive' => $quantity > 0
                    ? $stock->receive($stockable, $quantity, $aide)
                    : throw new InvalidArgumentException(__('Receive quantity must be positive.')),
                'adjust' => $stock->adjust($stockable, StockLocation::BackStorage, $quantity, $aide),
                'issue' => $quantity > 0
                    ? $stock->transfer($stockable, $quantity, StockMovementReason::ShiftIssue, $aide)
                    : throw new InvalidArgumentException(__('Issue quantity must be positive.')),
                'replenish' => $quantity > 0
                    ? $stock->transfer($stockable, $quantity, StockMovementReason::Replenish, $aide)
                    : throw new InvalidArgumentException(__('Replenish quantity must be positive.')),
                'use' => $stockable instanceof Supply
                    ? ($quantity > 0
                        ? $stock->recordConsumableUse($stockable, $quantity, $aide)
                        : throw new InvalidArgumentException(__('Use quantity must be positive.')))
                    : throw new InvalidArgumentException(__('Only supplies can be manually used from front stock.')),
            };
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        app(StationSessionService::class)->bump(StationType::Stock, $aide);

        unset($this->medicines, $this->injections, $this->dripBases, $this->supplies);
        $this->hydrateQuantityForms();

        Flux::toast(variant: 'success', text: __('Stock updated.'));
    }

    /**
     * @return Medicine|Injection|DripBase|Supply
     */
    private function resolveStockable(string $type, int $id): Model
    {
        return match ($type) {
            'medicine' => Medicine::query()->findOrFail($id),
            'injection' => Injection::query()->findOrFail($id),
            'dripBase' => DripBase::query()->findOrFail($id),
            'supply' => Supply::query()->findOrFail($id),
            default => throw new InvalidArgumentException(__('Unknown stock item type.')),
        };
    }

    private function hydrateQuantityForms(): void
    {
        $this->quantities = [
            'medicine' => [],
            'injection' => [],
            'dripBase' => [],
            'supply' => [],
        ];

        foreach ($this->medicines as $medicine) {
            $this->quantities['medicine'][$medicine->id] = (string) $this->defaultQuantityFor($medicine);
        }

        foreach ($this->injections as $injection) {
            $this->quantities['injection'][$injection->id] = (string) $this->defaultQuantityFor($injection);
        }

        foreach ($this->dripBases as $dripBase) {
            $this->quantities['dripBase'][$dripBase->id] = (string) $this->defaultQuantityFor($dripBase);
        }

        foreach ($this->supplies as $supply) {
            $this->quantities['supply'][$supply->id] = (string) $this->defaultQuantityFor($supply);
        }
    }

    private function defaultQuantityFor(Medicine|Injection|DripBase|Supply $item): int
    {
        return match ($this->activeMode) {
            'back' => $item->stockBalance(StockLocation::BackStorage),
            'front' => $item->stockBalance(StockLocation::FrontWorking),
            default => 0,
        };
    }
}; ?>

<div class="flex min-h-screen flex-col bg-zinc-950 text-white" wire:poll.30s>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-800 px-4 py-3">
        <div>
            <flux:heading level="1" size="lg">{{ __('Catalog Stock') }}</flux:heading>
            @if ($this->currentAideName)
                <flux:text class="text-zinc-400">{{ __('Signed in as') }} {{ $this->currentAideName }}</flux:text>
            @endif
        </div>
        <flux:button type="button" variant="ghost" icon="lock-closed" wire:click="lock">
            {{ __('Lock') }}
        </flux:button>
    </div>

    @if (! $showPinModal)
        <div class="flex flex-1 flex-col gap-4 p-4">
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Stock modes') }}">
                @foreach ([
                    'back' => __('Back Storage'),
                    'issue' => __('Issue to Front'),
                    'replenish' => __('Replenish'),
                    'front' => __('Front Stock'),
                ] as $mode => $label)
                    <flux:button
                        type="button"
                        size="sm"
                        :variant="$activeMode === $mode ? 'primary' : 'ghost'"
                        wire:click="switchMode('{{ $mode }}')"
                    >
                        {{ $label }}
                    </flux:button>
                @endforeach
            </nav>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <nav class="flex flex-wrap gap-2" aria-label="{{ __('Catalog tabs') }}">
                    @foreach ([
                        'medicines' => __('Medicines'),
                        'injections' => __('Injections'),
                        'dripBases' => __('Drips'),
                        'supplies' => __('Supplies'),
                    ] as $tab => $label)
                        <flux:button
                            type="button"
                            size="sm"
                            :variant="$activeTab === $tab ? 'primary' : 'ghost'"
                            wire:click="switchTab('{{ $tab }}')"
                        >
                            {{ $label }}
                        </flux:button>
                    @endforeach
                </nav>

                <div class="w-full sm:max-w-xs">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Search…')"
                    />
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900">
                @php
                    $items = match ($activeTab) {
                        'medicines' => $this->medicines,
                        'injections' => $this->injections,
                        'dripBases' => $this->dripBases,
                        'supplies' => $this->supplies,
                    };
                    $typeKey = match ($activeTab) {
                        'medicines' => 'medicine',
                        'injections' => 'injection',
                        'dripBases' => 'dripBase',
                        'supplies' => 'supply',
                    };
                @endphp

                <div class="hidden border-b border-zinc-800 px-4 py-2 text-xs uppercase tracking-wide text-zinc-500 sm:grid sm:grid-cols-12 sm:gap-3">
                    <div class="sm:col-span-5">{{ __('Item') }}</div>
                    <div class="sm:col-span-2">{{ __('Back') }}</div>
                    <div class="sm:col-span-2">{{ __('Front') }}</div>
                    <div class="sm:col-span-3">{{ __('Action') }}</div>
                </div>

                <div class="divide-y divide-zinc-800">
                    @forelse ($items as $item)
                        <div wire:key="stock-{{ $typeKey }}-{{ $item->id }}" class="flex flex-col gap-3 p-4 sm:grid sm:grid-cols-12 sm:items-center sm:gap-3">
                            <div class="min-w-0 sm:col-span-5">
                                <p class="truncate font-medium">{{ $item->name }}</p>
                                <p class="text-sm text-zinc-400">
                                    @if ($item instanceof \App\Models\Supply)
                                        {{ $item->category }}
                                        @if (filled($item->unit))
                                            · {{ $item->unit }}
                                        @endif
                                    @elseif ($item instanceof \App\Models\Medicine)
                                        {{ $item->short_form ?: '—' }}
                                        @if (filled($item->unit))
                                            · {{ $item->unit }}
                                        @endif
                                    @elseif ($item instanceof \App\Models\Injection)
                                        {{ $item->short_form ?: '—' }}
                                        · {{ $item->default_administration_type->label() }}
                                    @else
                                        {{ rtrim(rtrim(number_format($item->default_volume_ml, 2), '0'), '.') }} ml
                                    @endif
                                </p>
                            </div>

                            <div class="text-sm text-zinc-300 sm:col-span-2">
                                <span class="sm:hidden text-zinc-500">{{ __('Back') }}:</span>
                                {{ $item->stockBalance(\App\Enums\StockLocation::BackStorage) }}
                            </div>

                            <div class="text-sm text-zinc-300 sm:col-span-2">
                                <span class="sm:hidden text-zinc-500">{{ __('Front') }}:</span>
                                {{ $item->stockBalance(\App\Enums\StockLocation::FrontWorking) }}
                            </div>

                            <div class="flex flex-wrap items-center gap-2 sm:col-span-3">
                                @if ($activeMode === 'front' && $typeKey === 'supply')
                                    <flux:input
                                        type="number"
                                        wire:model="quantities.{{ $typeKey }}.{{ $item->id }}"
                                        class="w-24"
                                        min="0"
                                    />
                                    <flux:button type="button" variant="primary" size="sm" wire:click="useFromFront('{{ $typeKey }}', {{ $item->id }})">
                                        {{ __('Use') }}
                                    </flux:button>
                                @elseif ($activeMode === 'front')
                                    <flux:text class="text-zinc-500">{{ __('View only') }}</flux:text>
                                @else
                                    <flux:input
                                        type="number"
                                        wire:model="quantities.{{ $typeKey }}.{{ $item->id }}"
                                        class="w-24"
                                        min="0"
                                    />
                                    @if ($activeMode === 'back')
                                        <flux:button type="button" variant="ghost" size="sm" wire:click="receiveStock('{{ $typeKey }}', {{ $item->id }})">
                                            {{ __('Receive') }}
                                        </flux:button>
                                        <flux:button type="button" variant="primary" size="sm" wire:click="adjustBackStock('{{ $typeKey }}', {{ $item->id }})">
                                            {{ __('Set Back') }}
                                        </flux:button>
                                    @elseif ($activeMode === 'issue')
                                        <flux:button type="button" variant="primary" size="sm" wire:click="issueToFront('{{ $typeKey }}', {{ $item->id }})">
                                            {{ __('Issue') }}
                                        </flux:button>
                                    @elseif ($activeMode === 'replenish')
                                        <flux:button type="button" variant="primary" size="sm" wire:click="replenishFront('{{ $typeKey }}', {{ $item->id }})">
                                            {{ __('Replenish') }}
                                        </flux:button>
                                    @endif
                                @endif
                            </div>

                            <flux:error name="quantities.{{ $typeKey }}.{{ $item->id }}" class="sm:col-span-12" />
                        </div>
                    @empty
                        <div class="px-4 py-12 text-center text-zinc-500">
                            {{ __('No items found.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    @if ($showPinModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/95 p-4">
            <div class="w-full max-w-sm rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-xl">
                <flux:heading level="2" size="lg" class="text-center">
                    {{ __('Enter PIN') }}
                </flux:heading>
                <flux:text class="mt-2 text-center text-zinc-500">
                    {{ __('Enter your health aide PIN to manage stock. Session lasts 10 minutes.') }}
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
