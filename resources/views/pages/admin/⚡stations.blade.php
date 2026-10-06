<?php

use App\Models\Station;
use App\Services\StationDeviceService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Stations')] class extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $location = '';

    /**
     * @var list<string>
     */
    public array $allowedPages = [];

    public bool $isActive = true;

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }

    /**
     * @return Collection<int, Station>
     */
    #[Computed]
    public function stations(): Collection
    {
        return Station::query()
            ->orderByDesc('is_active')
            ->orderBy('location')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function thisDevice(): ?Station
    {
        return app(StationDeviceService::class)->resolve(request());
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function editStation(int $id): void
    {
        $station = Station::query()->findOrFail($id);

        $this->editingId = $station->id;
        $this->name = $station->name;
        $this->location = (string) $station->location;
        $this->allowedPages = $station->allowed_pages;
        $this->isActive = $station->is_active;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function saveStation(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'allowedPages' => ['required', 'array', 'min:1'],
            'allowedPages.*' => ['string', Rule::in(array_keys(Station::PAGES))],
            'isActive' => ['required', 'boolean'],
        ], [
            'allowedPages.required' => __('Pick at least one page this station can open.'),
        ]);

        $attributes = [
            'name' => $validated['name'],
            'location' => filled($validated['location']) ? $validated['location'] : null,
            'allowed_pages' => array_values($validated['allowedPages']),
            'is_active' => $validated['isActive'],
        ];

        if ($this->editingId !== null) {
            Station::query()->findOrFail($this->editingId)->update($attributes);
            Flux::toast(variant: 'success', text: __('Station updated.'));
        } else {
            Station::query()->create($attributes);
            Flux::toast(variant: 'success', text: __('Station created.'));
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->stations);
    }

    /**
     * Register the browser this admin is using as the given station's PC.
     */
    public function registerThisDevice(int $id, StationDeviceService $stationDevices): void
    {
        $station = Station::query()->findOrFail($id);

        $token = $stationDevices->issueToken($station);
        Cookie::queue($stationDevices->makeCookie($token, request()));

        $station->refresh();
        request()->attributes->set(StationDeviceService::REQUEST_ATTRIBUTE, $station);
        unset($this->stations, $this->thisDevice);

        Flux::toast(variant: 'success', text: __('This PC is now registered as :name. You can sign out; the station pages will keep working.', ['name' => $station->name]));
    }

    /**
     * Detach whatever PC is registered to the station.
     */
    public function unregister(int $id, StationDeviceService $stationDevices): void
    {
        $station = Station::query()->findOrFail($id);
        $stationDevices->unregister($station);

        if ($this->thisDevice?->is($station)) {
            Cookie::queue($stationDevices->forgetCookie());
            request()->attributes->remove(StationDeviceService::REQUEST_ATTRIBUTE);
        }

        unset($this->stations, $this->thisDevice);

        Flux::toast(text: __('PC unregistered from :name.', ['name' => $station->name]));
    }

    public function toggleActive(int $id): void
    {
        $station = Station::query()->findOrFail($id);
        $station->update(['is_active' => ! $station->is_active]);
        unset($this->stations, $this->thisDevice);

        Flux::toast(
            variant: 'success',
            text: $station->is_active ? __('Station enabled.') : __('Station disabled.'),
        );
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->location = '';
        $this->allowedPages = [];
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.15s>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading level="1">{{ __('Stations') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __(':online of :total stations online', [
                    'online' => $this->stations->filter->isOnline()->count(),
                    'total' => $this->stations->where('is_active', true)->count(),
                ]) }}
            </flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
            {{ __('Add station') }}
        </flux:button>
    </div>

    @if ($this->thisDevice)
        <flux:callout icon="computer-desktop" color="green">
            <flux:callout.heading>{{ __('This PC is registered as :name', ['name' => $this->thisDevice->name]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('It can open its station pages without signing in. Health aides use their PIN for actions.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout icon="information-circle">
            <flux:callout.text>
                {{ __('To set up a station PC: sign in on that PC, open this page, press "Register this PC" on its station, then sign out. Set the PC browser\'s home page to :url.', ['url' => route('station.home')]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Station') }}</flux:table.column>
                <flux:table.column>{{ __('Pages') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Last seen') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->stations as $station)
                    <flux:table.row wire:key="station-{{ $station->id }}">
                        <flux:table.cell>
                            <div class="font-medium">{{ $station->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $station->location ?? '—' }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($station->allowed_pages as $page)
                                    <flux:badge size="sm" wire:key="station-{{ $station->id }}-page-{{ $page }}">{{ __(Station::PAGES[$page] ?? $page) }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if (! $station->is_active)
                                <flux:badge size="sm" color="zinc">{{ __('Disabled') }}</flux:badge>
                            @elseif (! $station->isRegistered())
                                <flux:badge size="sm" color="amber">{{ __('No PC registered') }}</flux:badge>
                            @elseif ($station->isOnline())
                                <flux:badge size="sm" color="green" icon="signal">{{ __('Online') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="red" icon="signal-slash">{{ __('Offline') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($station->last_seen_at)
                                <div title="{{ $station->last_seen_at->format('d M Y, h:i:s A') }}">{{ $station->last_seen_at->diffForHumans() }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ $station->last_page ? __(Station::PAGES[$station->last_page] ?? $station->last_page) : '' }}
                                    @if ($station->last_ip) · {{ $station->last_ip }} @endif
                                </div>
                            @else
                                <span class="text-zinc-500">{{ __('Never') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-2">
                                @if ($this->thisDevice?->is($station))
                                    <flux:badge size="sm" color="green">{{ __('This PC') }}</flux:badge>
                                @elseif ($station->is_active)
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="computer-desktop"
                                        wire:click="registerThisDevice({{ $station->id }})"
                                        wire:confirm="{{ $station->isRegistered() ? __('Register this PC as :name? The PC currently registered to it will lose access.', ['name' => $station->name]) : __('Register this PC as :name?', ['name' => $station->name]) }}"
                                    >
                                        {{ __('Register this PC') }}
                                    </flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" wire:click="editStation({{ $station->id }})">
                                    {{ __('Edit') }}
                                </flux:button>
                                @if ($station->isRegistered())
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="unregister({{ $station->id }})"
                                        wire:confirm="{{ __('Unregister the PC from :name? It will no longer open station pages.', ['name' => $station->name]) }}"
                                    >
                                        {{ __('Unregister') }}
                                    </flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $station->id }})">
                                    {{ $station->is_active ? __('Disable') : __('Enable') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500">
                            {{ __('No stations yet.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="max-w-md">
        <form wire:submit="saveStation" class="space-y-4">
            <flux:heading size="lg">
                {{ $editingId ? __('Edit station') : __('Add station') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" placeholder="{{ __('e.g. ER PC 1') }}" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Location') }}</flux:label>
                <flux:input wire:model="location" placeholder="{{ __('e.g. ER Bay, Ground floor') }}" />
                <flux:error name="location" />
            </flux:field>

            <flux:checkbox.group wire:model="allowedPages" :label="__('Pages this PC can open')">
                @foreach (Station::PAGES as $route => $label)
                    <flux:checkbox :value="$route" :label="__($label)" wire:key="page-option-{{ $route }}" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="allowedPages" />

            <flux:field>
                <flux:label>{{ __('Enabled') }}</flux:label>
                <flux:switch wire:model="isActive" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
