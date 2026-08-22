<?php

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\HealthAide;
use App\Services\AttendanceProcessingService;
use App\Services\ZktecoSyncService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Attendance Device')] class extends Component
{
    use WithPagination;

    public ?int $mappingPunchId = null;

    public ?int $mapToHealthAideId = null;

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }
    }

    #[Computed]
    public function device(): AttendanceDevice
    {
        return AttendanceDevice::defaultDevice();
    }

    #[Computed]
    public function healthAides()
    {
        return HealthAide::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function unmappedPunches()
    {
        return AttendancePunch::query()
            ->whereNull('health_aide_id')
            ->latest('punched_at')
            ->paginate(10);
    }

    public function syncNow(ZktecoSyncService $syncService, AttendanceProcessingService $processingService): void
    {
        try {
            $result = $syncService->sync();
            $processingService->processRecentAssignments();
            Flux::toast(variant: 'success', text: __('Synced :count new punch(es).', ['count' => $result['imported']]));
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        unset($this->device, $this->unmappedPunches);
    }

    public function testConnection(ZktecoSyncService $syncService): void
    {
        try {
            $syncService->testConnection();
            Flux::toast(variant: 'success', text: __('Device connection successful.'));
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function enroll(int $healthAideId, ZktecoSyncService $syncService): void
    {
        $aide = HealthAide::query()->findOrFail($healthAideId);

        try {
            $syncService->enrollHealthAide($aide);
            Flux::toast(variant: 'success', text: __(':name enrolled on device.', ['name' => $aide->name]));
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        unset($this->healthAides, $this->device);
    }

    public function mapPunch(int $punchId): void
    {
        $this->mappingPunchId = $punchId;
        $this->mapToHealthAideId = null;
    }

    public function saveMapping(): void
    {
        $validated = $this->validate([
            'mappingPunchId' => ['required', 'exists:attendance_punches,id'],
            'mapToHealthAideId' => ['required', 'exists:health_aides,id'],
        ]);

        $punch = AttendancePunch::query()->findOrFail($validated['mappingPunchId']);
        $aide = HealthAide::query()->findOrFail($validated['mapToHealthAideId']);

        $punch->update(['health_aide_id' => $aide->id]);
        $aide->update(['device_user_id' => $punch->device_user_id]);

        $this->mappingPunchId = null;
        unset($this->unmappedPunches);
        Flux::toast(variant: 'success', text: __('Punch mapped to :name.', ['name' => $aide->name]));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Attendance Device') }}</flux:heading>

    <flux:card>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <flux:heading size="lg">{{ $this->device->name }}</flux:heading>
                <flux:text>{{ $this->device->ip_address }}:{{ $this->device->port }}</flux:text>
                <flux:text>{{ __('Last sync') }}: {{ $this->device->last_sync_at?->toDateTimeString() ?? __('Never') }}</flux:text>
                @if ($this->device->last_sync_error)
                    <flux:text class="text-red-600">{{ $this->device->last_sync_error }}</flux:text>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="testConnection" icon="signal">{{ __('Test Connection') }}</flux:button>
                <flux:button wire:click="syncNow" variant="primary" icon="arrow-path">{{ __('Sync Now') }}</flux:button>
            </div>
        </div>
    </flux:card>

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Enroll Health Aides') }}</flux:heading>
        <div class="space-y-2">
            @foreach ($this->healthAides as $aide)
                <div wire:key="enroll-{{ $aide->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div>
                        <div class="font-medium">{{ $aide->name }}</div>
                        <flux:text>{{ $aide->isAttendanceEnrolled() ? __('Enrolled as ID :id', ['id' => $aide->device_user_id]) : __('Not enrolled') }}</flux:text>
                    </div>
                    <flux:button size="sm" wire:click="enroll({{ $aide->id }})">{{ __('Enroll') }}</flux:button>
                </div>
            @endforeach
        </div>
    </flux:card>

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Unmapped Punches') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Device User ID') }}</flux:table.column>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->unmappedPunches as $punch)
                    <flux:table.row wire:key="unmap-{{ $punch->id }}">
                        <flux:table.cell>{{ $punch->device_user_id }}</flux:table.cell>
                        <flux:table.cell>{{ $punch->punched_at->format('M j, H:i') }}</flux:table.cell>
                        <flux:table.cell><flux:button size="sm" wire:click="mapPunch({{ $punch->id }})">{{ __('Map') }}</flux:button></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="3">{{ __('All punches are mapped.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        {{ $this->unmappedPunches->links() }}
    </flux:card>

    @if ($mappingPunchId)
        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Map Punch to Health Aide') }}</flux:heading>
            <form wire:submit="saveMapping" class="flex flex-wrap items-end gap-4">
                <flux:select wire:model="mapToHealthAideId" label="{{ __('Health Aide') }}" required>
                    <option value="">{{ __('Select aide') }}</option>
                    @foreach ($this->healthAides as $aide)
                        <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" variant="primary">{{ __('Save Mapping') }}</flux:button>
                <flux:button type="button" wire:click="$set('mappingPunchId', null)">{{ __('Cancel') }}</flux:button>
            </form>
        </flux:card>
    @endif
</div>
