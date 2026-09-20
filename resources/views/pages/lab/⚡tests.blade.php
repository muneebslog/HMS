<?php

use App\Models\LabTest;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Lab Fields')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $inHouseOnly = false;

    /**
     * Get the paginated lab tests based on the search filter.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, LabTest>
     */
    #[Computed]
    public function labTests()
    {
        return LabTest::query()
            ->withCount('fields')
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('test_name', 'like', "%{$this->search}%")
                        ->orWhere('test_code', 'like', "%{$this->search}%");
                });
            })
            ->when($this->inHouseOnly, function ($query) {
                $query->where('is_in_house', true);
            })
            ->orderBy('test_name')
            ->paginate(15);
    }

    /**
     * Reset pagination when the search term changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the in-house filter changes.
     */
    public function updatedInHouseOnly(): void
    {
        $this->resetPage();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Lab Fields') }}</flux:heading>
        </div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by test name or code...') }}"
                icon="magnifying-glass"
                class="w-full sm:max-w-md"
            />

            <flux:switch wire:model.live="inHouseOnly" :label="__('In-house only')" />
        </div>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Test name') }}</flux:table.column>
                    <flux:table.column>{{ __('Code') }}</flux:table.column>
                    <flux:table.column>{{ __('Sample') }}</flux:table.column>
                    <flux:table.column>{{ __('Fields') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->labTests as $labTest)
                        <flux:table.row wire:key="lab-test-{{ $labTest->id }}">
                            <flux:table.cell class="font-medium">{{ $labTest->test_name }}</flux:table.cell>
                            <flux:table.cell>{{ $labTest->test_code ?: '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $labTest->sample ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="{{ $labTest->fields_count > 1 ? 'green' : 'zinc' }}">
                                    {{ trans_choice(':count field|:count fields', $labTest->fields_count, ['count' => $labTest->fields_count]) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button size="sm" variant="ghost" icon:trailing="chevron-right" :href="route('lab.tests.fields', $labTest)" wire:navigate>
                                    {{ __('Manage Fields') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">
                                {{ __('No lab tests found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->labTests->links() }}
            </div>
        </flux:card>
    </div>
</div>
