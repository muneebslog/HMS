<?php

use App\Models\HealthAide;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Health Aides')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $pin = '';

    public bool $isActive = true;

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, HealthAide>
     */
    #[Computed]
    public function aides()
    {
        return HealthAide::query()
            ->when($this->search !== '', function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%');
            })
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15);
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function editAide(int $id): void
    {
        $aide = HealthAide::query()->findOrFail($id);

        $this->editingId = $aide->id;
        $this->name = $aide->name;
        $this->pin = '';
        $this->isActive = $aide->is_active;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function saveAide(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'isActive' => ['required', 'boolean'],
            'pin' => [
                Rule::requiredIf($this->editingId === null),
                'nullable',
                'digits_between:4,6',
            ],
        ];

        $validated = $this->validate($rules);

        if (filled($validated['pin']) && HealthAide::pinIsTaken($validated['pin'], $this->editingId)) {
            $this->addError('pin', __('This PIN is already used by another active health aide.'));

            return;
        }

        $attributes = [
            'name' => $validated['name'],
            'is_active' => $validated['isActive'],
        ];

        if (filled($validated['pin'])) {
            $attributes['pin'] = $validated['pin'];
        }

        if ($this->editingId !== null) {
            HealthAide::query()->findOrFail($this->editingId)->update($attributes);
            Flux::toast(variant: 'success', text: __('Health aide updated.'));
        } else {
            HealthAide::query()->create($attributes);
            Flux::toast(variant: 'success', text: __('Health aide created.'));
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->aides);
    }

    public function toggleActive(int $id): void
    {
        $aide = HealthAide::query()->findOrFail($id);
        $aide->update(['is_active' => ! $aide->is_active]);
        unset($this->aides);

        Flux::toast(
            variant: 'success',
            text: $aide->is_active ? __('Health aide activated.') : __('Health aide deactivated.'),
        );
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->pin = '';
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Health Aides') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
            {{ __('Add health aide') }}
        </flux:button>
    </div>

    <flux:card>
        <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:text>{{ __('Manage PIN profiles for medication and drip delivery kiosks.') }}</flux:text>

            <div class="flex flex-col gap-4 sm:flex-row">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search name...') }}"
                    class="w-full sm:w-56"
                />
                <flux:select wire:model.live="statusFilter" class="w-full sm:w-40">
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </flux:select>
            </div>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->aides as $aide)
                    <flux:table.row wire:key="health-aide-{{ $aide->id }}">
                        <flux:table.cell class="font-medium">{{ $aide->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$aide->is_active ? 'green' : 'zinc'">
                                {{ $aide->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="editAide({{ $aide->id }})">
                                    {{ __('Edit') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $aide->id }})">
                                    {{ $aide->is_active ? __('Deactivate') : __('Activate') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="text-center text-zinc-500">
                            {{ __('No health aides yet.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">
            {{ $this->aides->links() }}
        </div>
    </flux:card>

    <flux:modal wire:model="showModal" class="max-w-md">
        <form wire:submit="saveAide" class="space-y-4">
            <flux:heading size="lg">
                {{ $editingId ? __('Edit health aide') : __('Add health aide') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('PIN') }}</flux:label>
                <flux:input
                    type="password"
                    wire:model="pin"
                    inputmode="numeric"
                    maxlength="6"
                    placeholder="{{ $editingId ? __('Leave blank to keep current PIN') : '----' }}"
                />
                <flux:description>{{ __('4–6 digits. Must be unique among active aides.') }}</flux:description>
                <flux:error name="pin" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Active') }}</flux:label>
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
