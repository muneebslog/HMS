<?php

use App\Enums\StationType;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
use App\Services\HealthAidePinSession;
use App\Services\StationSessionService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Catalog Stock')] class extends Component
{
    public string $pin = '';

    public bool $showPinModal = false;

    public string $search = '';

    public string $activeTab = 'medicines';

    /**
     * @var array<int|string, string>
     */
    public array $medicineStocks = [];

    /**
     * @var array<int|string, string>
     */
    public array $injectionStocks = [];

    /**
     * @var array<int|string, string>
     */
    public array $dripBaseStocks = [];

    public function mount(HealthAidePinSession $pinSession): void
    {
        if (! $pinSession->check()) {
            $this->showPinModal = true;

            return;
        }

        $this->hydrateStockForms();
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

    #[Computed]
    public function currentAideName(): ?string
    {
        return app(HealthAidePinSession::class)->current()?->name;
    }

    public function updatedSearch(): void
    {
        unset($this->medicines, $this->injections, $this->dripBases);
    }

    public function switchTab(string $tab): void
    {
        if (! in_array($tab, ['medicines', 'injections', 'dripBases'], true)) {
            return;
        }

        $this->activeTab = $tab;
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
        $this->hydrateStockForms();

        Flux::toast(variant: 'success', text: __('Unlocked as :name', ['name' => $aide->name]));
    }

    public function lock(HealthAidePinSession $pinSession, StationSessionService $stationSessions): void
    {
        $pinSession->forget();
        $stationSessions->clear(StationType::Stock);
        $this->showPinModal = true;
        $this->medicineStocks = [];
        $this->injectionStocks = [];
        $this->dripBaseStocks = [];
        Flux::toast(text: __('Session locked.'));
    }

    public function saveMedicineStock(int $medicineId): void
    {
        $this->saveStock('medicine', $medicineId);
    }

    public function saveInjectionStock(int $injectionId): void
    {
        $this->saveStock('injection', $injectionId);
    }

    public function saveDripBaseStock(int $dripBaseId): void
    {
        $this->saveStock('dripBase', $dripBaseId);
    }

    private function saveStock(string $type, int $id): void
    {
        $aide = app(HealthAidePinSession::class)->current();

        if ($aide === null) {
            $this->showPinModal = true;
            Flux::toast(variant: 'danger', text: __('Enter your PIN to continue.'));

            return;
        }

        $field = match ($type) {
            'medicine' => "medicineStocks.{$id}",
            'injection' => "injectionStocks.{$id}",
            'dripBase' => "dripBaseStocks.{$id}",
        };

        $this->validate([
            $field => ['required', 'integer'],
        ]);

        $quantity = (int) match ($type) {
            'medicine' => $this->medicineStocks[$id],
            'injection' => $this->injectionStocks[$id],
            'dripBase' => $this->dripBaseStocks[$id],
        };

        match ($type) {
            'medicine' => Medicine::query()->whereKey($id)->update(['stock_quantity' => $quantity]),
            'injection' => Injection::query()->whereKey($id)->update(['stock_quantity' => $quantity]),
            'dripBase' => DripBase::query()->whereKey($id)->update(['stock_quantity' => $quantity]),
        };

        app(StationSessionService::class)->bump(StationType::Stock, $aide);

        unset($this->medicines, $this->injections, $this->dripBases);

        Flux::toast(variant: 'success', text: __('Stock updated.'));
    }

    private function hydrateStockForms(): void
    {
        $this->medicineStocks = Medicine::query()
            ->active()
            ->pluck('stock_quantity', 'id')
            ->map(fn (int $qty): string => (string) $qty)
            ->all();

        $this->injectionStocks = Injection::query()
            ->active()
            ->pluck('stock_quantity', 'id')
            ->map(fn (int $qty): string => (string) $qty)
            ->all();

        $this->dripBaseStocks = DripBase::query()
            ->active()
            ->pluck('stock_quantity', 'id')
            ->map(fn (int $qty): string => (string) $qty)
            ->all();
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
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <nav class="flex flex-wrap gap-2" aria-label="{{ __('Catalog tabs') }}">
                    <flux:button
                        type="button"
                        size="sm"
                        :variant="$activeTab === 'medicines' ? 'primary' : 'ghost'"
                        wire:click="switchTab('medicines')"
                    >
                        {{ __('Medicines') }}
                        <flux:badge size="sm" color="zinc" class="ms-1">{{ $this->medicines->count() }}</flux:badge>
                    </flux:button>
                    <flux:button
                        type="button"
                        size="sm"
                        :variant="$activeTab === 'injections' ? 'primary' : 'ghost'"
                        wire:click="switchTab('injections')"
                    >
                        {{ __('Injections') }}
                        <flux:badge size="sm" color="zinc" class="ms-1">{{ $this->injections->count() }}</flux:badge>
                    </flux:button>
                    <flux:button
                        type="button"
                        size="sm"
                        :variant="$activeTab === 'dripBases' ? 'primary' : 'ghost'"
                        wire:click="switchTab('dripBases')"
                    >
                        {{ __('Drips') }}
                        <flux:badge size="sm" color="zinc" class="ms-1">{{ $this->dripBases->count() }}</flux:badge>
                    </flux:button>
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
                @if ($activeTab === 'medicines')
                    <div class="divide-y divide-zinc-800">
                        @forelse ($this->medicines as $medicine)
                            <div wire:key="stock-med-{{ $medicine->id }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $medicine->name }}</p>
                                    <p class="text-sm text-zinc-400">
                                        {{ $medicine->short_form ?: '—' }}
                                        @if (filled($medicine->unit))
                                            · {{ $medicine->unit }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <flux:input
                                        type="number"
                                        wire:model="medicineStocks.{{ $medicine->id }}"
                                        class="w-28"
                                    />
                                    <flux:button type="button" variant="primary" size="sm" wire:click="saveMedicineStock({{ $medicine->id }})">
                                        {{ __('Save') }}
                                    </flux:button>
                                </div>
                                <flux:error name="medicineStocks.{{ $medicine->id }}" />
                            </div>
                        @empty
                            <div class="px-4 py-12 text-center text-zinc-500">
                                {{ __('No medicines found.') }}
                            </div>
                        @endforelse
                    </div>
                @elseif ($activeTab === 'injections')
                    <div class="divide-y divide-zinc-800">
                        @forelse ($this->injections as $injection)
                            <div wire:key="stock-inj-{{ $injection->id }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $injection->name }}</p>
                                    <p class="text-sm text-zinc-400">
                                        {{ $injection->short_form ?: '—' }}
                                        · {{ $injection->default_administration_type->label() }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <flux:input
                                        type="number"
                                        wire:model="injectionStocks.{{ $injection->id }}"
                                        class="w-28"
                                    />
                                    <flux:button type="button" variant="primary" size="sm" wire:click="saveInjectionStock({{ $injection->id }})">
                                        {{ __('Save') }}
                                    </flux:button>
                                </div>
                                <flux:error name="injectionStocks.{{ $injection->id }}" />
                            </div>
                        @empty
                            <div class="px-4 py-12 text-center text-zinc-500">
                                {{ __('No injections found.') }}
                            </div>
                        @endforelse
                    </div>
                @else
                    <div class="divide-y divide-zinc-800">
                        @forelse ($this->dripBases as $dripBase)
                            <div wire:key="stock-drip-{{ $dripBase->id }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $dripBase->name }}</p>
                                    <p class="text-sm text-zinc-400">
                                        {{ rtrim(rtrim(number_format($dripBase->default_volume_ml, 2), '0'), '.') }} ml
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <flux:input
                                        type="number"
                                        wire:model="dripBaseStocks.{{ $dripBase->id }}"
                                        class="w-28"
                                    />
                                    <flux:button type="button" variant="primary" size="sm" wire:click="saveDripBaseStock({{ $dripBase->id }})">
                                        {{ __('Save') }}
                                    </flux:button>
                                </div>
                                <flux:error name="dripBaseStocks.{{ $dripBase->id }}" />
                            </div>
                        @empty
                            <div class="px-4 py-12 text-center text-zinc-500">
                                {{ __('No drip bases found.') }}
                            </div>
                        @endforelse
                    </div>
                @endif
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
