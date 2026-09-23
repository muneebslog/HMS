<?php

use App\Enums\LabFieldType;
use App\Models\LabField;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('All Lab Fields')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $type = '';

    public bool $unlinkedOnly = false;

    /**
     * Get the paginated lab fields, with their ranges and linked tests.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, LabField>
     */
    #[Computed]
    public function labFields()
    {
        return LabField::query()
            ->with([
                'ranges',
                'labTests' => fn ($query) => $query->orderBy('test_name'),
            ])
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('unit', 'like', "%{$this->search}%");
                });
            })
            ->when($this->type !== '', function ($query) {
                $query->where('type', $this->type);
            })
            ->when($this->unlinkedOnly, function ($query) {
                $query->doesntHave('labTests');
            })
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Get the options for the type filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return array_map(
            fn (LabFieldType $type) => ['value' => $type->value, 'label' => $type->label()],
            LabFieldType::cases(),
        );
    }

    /**
     * Reset pagination when the search term changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the type filter changes.
     */
    public function updatedType(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the unlinked filter changes.
     */
    public function updatedUnlinkedOnly(): void
    {
        $this->resetPage();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('All Lab Fields') }}</flux:heading>

            <flux:button size="sm" variant="ghost" icon="beaker" :href="route('lab.tests')" wire:navigate>
                {{ __('Lab Tests') }}
            </flux:button>
        </div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex w-full flex-col gap-4 sm:flex-row sm:items-center">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by field name or unit...') }}"
                    icon="magnifying-glass"
                    class="w-full sm:max-w-md"
                />

                <flux:select wire:model.live="type" class="w-full sm:max-w-40">
                    <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                    @foreach ($this->typeOptions as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:switch wire:model.live="unlinkedOnly" :label="__('Not linked to any test')" />
        </div>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Field') }}</flux:table.column>
                    <flux:table.column>{{ __('Unit') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Ranges / Options') }}</flux:table.column>
                    <flux:table.column>{{ __('Linked Tests') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->labFields as $labField)
                        <flux:table.row wire:key="lab-field-{{ $labField->id }}">
                            <flux:table.cell class="font-medium">
                                {{ $labField->name }}
                                @unless ($labField->is_active)
                                    <flux:badge size="sm" color="red" class="ms-1">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </flux:table.cell>
                            <flux:table.cell>{{ $labField->unit ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="match ($labField->type) {
                                    LabFieldType::Numeric => 'green',
                                    LabFieldType::Choice => 'blue',
                                    LabFieldType::Text => 'purple',
                                }">{{ $labField->type->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($labField->type === LabFieldType::Numeric)
                                        @forelse ($labField->ranges as $range)
                                            <flux:badge size="sm">{{ $range->formatted() }}</flux:badge>
                                        @empty
                                            <span class="text-zinc-400">—</span>
                                        @endforelse
                                    @elseif ($labField->type === LabFieldType::Choice)
                                        @foreach ($labField->options ?? [] as $option)
                                            <flux:badge size="sm" color="zinc">{{ $option }}</flux:badge>
                                        @endforeach
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($labField->labTests as $labTest)
                                        <a href="{{ route('lab.tests.fields', $labTest) }}" wire:navigate wire:key="lab-field-{{ $labField->id }}-test-{{ $labTest->id }}">
                                            <flux:badge size="sm" color="sky" class="hover:opacity-80">{{ $labTest->test_name }}</flux:badge>
                                        </a>
                                    @empty
                                        <flux:badge size="sm" color="amber">{{ __('Not linked') }}</flux:badge>
                                    @endforelse
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">
                                {{ __('No lab fields found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->labFields->links() }}
            </div>
        </flux:card>
    </div>
</div>
