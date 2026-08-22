<?php

use App\Enums\AttendanceRecordStatus;
use App\Enums\DutyAssignmentStatus;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\DutyAssignment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Attendance')] class extends Component
{
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }
    }

    #[Computed]
    public function todayAssignments()
    {
        return DutyAssignment::query()
            ->scheduled()
            ->whereDate('date', today())
            ->with(['healthAide', 'attendanceRecord'])
            ->orderBy('starts_at')
            ->get();
    }

    #[Computed]
    public function recentPunches()
    {
        return AttendancePunch::query()
            ->with(['healthAide', 'device'])
            ->latest('punched_at')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function device(): AttendanceDevice
    {
        return AttendanceDevice::defaultDevice();
    }

    #[Computed]
    public function stats(): array
    {
        $records = AttendanceRecord::query()->whereDate('date', today())->get();

        return [
            'scheduled' => $this->todayAssignments->count(),
            'present' => $records->whereIn('status', [AttendanceRecordStatus::Present, AttendanceRecordStatus::Late, AttendanceRecordStatus::EarlyLeave])->count(),
            'absent' => $records->where('status', AttendanceRecordStatus::Absent)->count(),
            'on_leave' => $records->where('status', AttendanceRecordStatus::OnLeave)->count(),
            'incomplete' => $records->where('status', AttendanceRecordStatus::Incomplete)->count(),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Attendance') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('admin.attendance.roster')" wire:navigate icon="calendar">{{ __('Roster') }}</flux:button>
            <flux:button :href="route('admin.attendance.daily')" wire:navigate icon="clipboard-document-check">{{ __('Daily Review') }}</flux:button>
            <flux:button :href="route('admin.attendance.payroll')" wire:navigate icon="banknotes">{{ __('Payroll') }}</flux:button>
            <flux:button :href="route('admin.attendance.device')" wire:navigate icon="cpu-chip">{{ __('Device') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <flux:card><flux:text>{{ __('Scheduled Today') }}</flux:text><flux:heading size="lg">{{ $this->stats['scheduled'] }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Present') }}</flux:text><flux:heading size="lg" class="text-green-600">{{ $this->stats['present'] }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Absent') }}</flux:text><flux:heading size="lg" class="text-red-600">{{ $this->stats['absent'] }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('On Leave') }}</flux:text><flux:heading size="lg">{{ $this->stats['on_leave'] }}</flux:heading></flux:card>
        <flux:card><flux:text>{{ __('Incomplete') }}</flux:text><flux:heading size="lg" class="text-amber-600">{{ $this->stats['incomplete'] }}</flux:heading></flux:card>
    </div>

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Device Status') }}</flux:heading>
        <div class="grid gap-2 sm:grid-cols-3">
            <flux:text>{{ $this->device->name }} ({{ $this->device->ip_address }}:{{ $this->device->port }})</flux:text>
            <flux:text>{{ __('Last sync') }}: {{ $this->device->last_sync_at?->diffForHumans() ?? __('Never') }}</flux:text>
            <flux:badge :color="$this->device->last_sync_status === 'success' ? 'green' : ($this->device->last_sync_status === 'failed' ? 'red' : 'zinc')">
                {{ $this->device->last_sync_status ?? __('Unknown') }}
            </flux:badge>
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Today\'s Duties') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Aide') }}</flux:table.column>
                    <flux:table.column>{{ __('Shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->todayAssignments as $assignment)
                        <flux:table.row wire:key="duty-{{ $assignment->id }}">
                            <flux:table.cell>{{ $assignment->healthAide->name }}</flux:table.cell>
                            <flux:table.cell>{{ $assignment->starts_at->format('H:i') }} - {{ $assignment->ends_at->format('H:i') }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($assignment->attendanceRecord)
                                    <flux:badge :color="$assignment->attendanceRecord->status->badgeColor()">{{ $assignment->attendanceRecord->status->label() }}</flux:badge>
                                @else
                                    <flux:badge color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="3">{{ __('No duties scheduled for today.') }}</flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Recent Punches') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Aide') }}</flux:table.column>
                    <flux:table.column>{{ __('Time') }}</flux:table.column>
                    <flux:table.column>{{ __('State') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->recentPunches as $punch)
                        <flux:table.row wire:key="punch-{{ $punch->id }}">
                            <flux:table.cell>{{ $punch->healthAide?->name ?? __('Unmapped') }} ({{ $punch->device_user_id }})</flux:table.cell>
                            <flux:table.cell>{{ $punch->punched_at->format('M j, H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $punch->punch_state ?? __('Raw') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="3">{{ __('No punches synced yet.') }}</flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
</div>
