<?php

use App\Models\AttendanceWorkSession;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Current Staff')] class extends Component
{
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }
    }

    #[Computed]
    public function openSessions()
    {
        return AttendanceWorkSession::query()
            ->open()
            ->with(['healthAide', 'inPunch', 'dutyAssignment'])
            ->orderBy('starts_at')
            ->get();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading level="1">{{ __('Current Staff') }}</flux:heading>
            <flux:text>{{ __('People with an open check-in and no check-out yet.') }}</flux:text>
        </div>
        <flux:button :href="route('admin.attendance.punches')" wire:navigate icon="finger-print">{{ __('Punches') }}</flux:button>
    </div>

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Health Aide') }}</flux:table.column>
                <flux:table.column>{{ __('Checked In') }}</flux:table.column>
                <flux:table.column>{{ __('Duration') }}</flux:table.column>
                <flux:table.column>{{ __('Roster') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->openSessions as $session)
                    <flux:table.row wire:key="open-session-{{ $session->id }}">
                        <flux:table.cell>{{ $session->healthAide->name }}</flux:table.cell>
                        <flux:table.cell>{{ $session->starts_at->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $session->starts_at->diffForHumans(now(), true) }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($session->dutyAssignment)
                                {{ $session->dutyAssignment->starts_at->format('H:i') }} - {{ $session->dutyAssignment->ends_at->format('H:i') }}
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$session->status->badgeColor()">{{ $session->status->label() }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">{{ __('No staff currently checked in.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
