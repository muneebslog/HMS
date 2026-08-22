<?php

use App\Models\AttendancePunch;
use App\Models\HealthAide;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Attendance Punches')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function punches()
    {
        return AttendancePunch::query()
            ->with(['healthAide', 'device'])
            ->when($this->search !== '', function ($query) {
                $query->where(function ($inner) {
                    $inner->where('device_user_id', 'like', '%'.$this->search.'%')
                        ->orWhereHas('healthAide', fn ($aide) => $aide->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->latest('punched_at')
            ->paginate(20);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Attendance Punches') }}</flux:heading>

    <flux:card>
        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search aide or device user ID...') }}" class="w-full sm:w-72" />
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Health Aide') }}</flux:table.column>
                <flux:table.column>{{ __('Device User ID') }}</flux:table.column>
                <flux:table.column>{{ __('Punched At') }}</flux:table.column>
                <flux:table.column>{{ __('State') }}</flux:table.column>
                <flux:table.column>{{ __('Processed') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->punches as $punch)
                    <flux:table.row wire:key="punch-log-{{ $punch->id }}">
                        <flux:table.cell>{{ $punch->healthAide?->name ?? __('Unmapped') }}</flux:table.cell>
                        <flux:table.cell>{{ $punch->device_user_id }}</flux:table.cell>
                        <flux:table.cell>{{ $punch->punched_at->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $punch->punch_state ?? __('Raw') }}</flux:table.cell>
                        <flux:table.cell>{{ $punch->processed_at?->format('Y-m-d H:i') ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No punches found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $this->punches->links() }}
    </flux:card>
</div>
