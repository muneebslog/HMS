<?php

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
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

    public bool $showLinkModal = false;

    public ?int $linkingDeviceUserId = null;

    public string $linkingDeviceUserLabel = '';

    public ?int $linkHealthAideId = null;

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
    public function deviceUsers()
    {
        return AttendanceDeviceUser::query()
            ->where('attendance_device_id', $this->device->id)
            ->with('healthAide')
            ->orderByRaw('case when health_aide_id is null then 0 else 1 end')
            ->orderBy('name')
            ->orderBy('device_user_id')
            ->get();
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

    public function fetchUsers(ZktecoSyncService $syncService): void
    {
        try {
            $result = $syncService->syncUsers();
            Flux::toast(variant: 'success', text: __('Fetched :count device user(s).', ['count' => $result['synced']]));
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        unset($this->deviceUsers, $this->device);
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

        unset($this->healthAides, $this->device, $this->deviceUsers);
    }

    public function startLinkDeviceUser(int $deviceUserId): void
    {
        $deviceUser = AttendanceDeviceUser::query()->findOrFail($deviceUserId);

        $this->linkingDeviceUserId = $deviceUser->id;
        $this->linkingDeviceUserLabel = trim(($deviceUser->name ?: __('User')).' (#'.$deviceUser->device_user_id.')');
        $this->linkHealthAideId = $deviceUser->health_aide_id;
        $this->resetValidation();
        $this->showLinkModal = true;
    }

    public function cancelLinkDeviceUser(): void
    {
        $this->showLinkModal = false;
        $this->linkingDeviceUserId = null;
        $this->linkingDeviceUserLabel = '';
        $this->linkHealthAideId = null;
        $this->resetValidation();
    }

    public function saveDeviceUserLink(ZktecoSyncService $syncService): void
    {
        $validated = $this->validate([
            'linkingDeviceUserId' => ['required', 'exists:attendance_device_users,id'],
            'linkHealthAideId' => ['required', 'exists:health_aides,id'],
        ]);

        $deviceUser = AttendanceDeviceUser::query()->findOrFail($validated['linkingDeviceUserId']);
        $aide = HealthAide::query()->findOrFail($validated['linkHealthAideId']);

        $syncService->linkDeviceUserToHealthAide($deviceUser, $aide);

        $this->cancelLinkDeviceUser();
        unset($this->deviceUsers, $this->healthAides, $this->unmappedPunches);
        Flux::toast(variant: 'success', text: __('Linked device user :id to :name.', [
            'id' => $deviceUser->device_user_id,
            'name' => $aide->name,
        ]));
    }

    public function unlinkDeviceUser(int $deviceUserId, ZktecoSyncService $syncService): void
    {
        $deviceUser = AttendanceDeviceUser::query()->findOrFail($deviceUserId);
        $syncService->unlinkDeviceUser($deviceUser);

        unset($this->deviceUsers, $this->healthAides);
        Flux::toast(variant: 'success', text: __('Device user unlinked.'));
    }

    public function mapPunch(int $punchId): void
    {
        $this->mappingPunchId = $punchId;
        $this->mapToHealthAideId = null;
    }

    public function saveMapping(ZktecoSyncService $syncService): void
    {
        $validated = $this->validate([
            'mappingPunchId' => ['required', 'exists:attendance_punches,id'],
            'mapToHealthAideId' => ['required', 'exists:health_aides,id'],
        ]);

        $punch = AttendancePunch::query()->findOrFail($validated['mappingPunchId']);
        $aide = HealthAide::query()->findOrFail($validated['mapToHealthAideId']);

        $deviceUser = AttendanceDeviceUser::query()->firstOrCreate(
            [
                'attendance_device_id' => $punch->attendance_device_id,
                'device_user_id' => $punch->device_user_id,
            ],
            [
                'name' => null,
                'last_seen_at' => now(),
            ],
        );

        $syncService->linkDeviceUserToHealthAide($deviceUser, $aide);

        $this->mappingPunchId = null;
        unset($this->unmappedPunches, $this->deviceUsers, $this->healthAides);
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
                <flux:button wire:click="fetchUsers" icon="users">{{ __('Fetch Users') }}</flux:button>
                <flux:button wire:click="syncNow" variant="primary" icon="arrow-path">{{ __('Sync Punches') }}</flux:button>
            </div>
        </div>
    </flux:card>

    <flux:card>
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="lg">{{ __('Device Users') }}</flux:heading>
                <flux:text>{{ __('Users enrolled on the K60. Link each device ID to a health aide account.') }}</flux:text>
            </div>
            <flux:button wire:click="fetchUsers" icon="arrow-path">{{ __('Refresh from Device') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Device User ID') }}</flux:table.column>
                <flux:table.column>{{ __('Name on Device') }}</flux:table.column>
                <flux:table.column>{{ __('Linked Health Aide') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->deviceUsers as $deviceUser)
                    <flux:table.row wire:key="device-user-{{ $deviceUser->id }}">
                        <flux:table.cell class="font-medium">{{ $deviceUser->device_user_id }}</flux:table.cell>
                        <flux:table.cell>{{ $deviceUser->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($deviceUser->healthAide)
                                <flux:badge color="green">{{ $deviceUser->healthAide->name }}</flux:badge>
                            @else
                                <flux:badge color="zinc">{{ __('Not linked') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" wire:click="startLinkDeviceUser({{ $deviceUser->id }})">
                                    {{ $deviceUser->isLinked() ? __('Change Link') : __('Link') }}
                                </flux:button>
                                @if ($deviceUser->isLinked())
                                    <flux:button size="sm" variant="ghost" wire:click="unlinkDeviceUser({{ $deviceUser->id }})" wire:confirm="{{ __('Unlink this device user?') }}">
                                        {{ __('Unlink') }}
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            {{ __('No device users loaded yet. Click “Fetch Users” to pull IDs and names from the K60.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Push Health Aide to Device') }}</flux:heading>
        <flux:text class="mb-4">{{ __('Optional: create a new user on the K60 from an HMS health aide (uses health aide ID as device user ID). Prefer linking existing device users above when they already have fingerprints.') }}</flux:text>
        <div class="space-y-2">
            @foreach ($this->healthAides as $aide)
                <div wire:key="enroll-{{ $aide->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div>
                        <div class="font-medium">{{ $aide->name }}</div>
                        <flux:text>{{ $aide->isAttendanceEnrolled() ? __('Linked as device ID :id', ['id' => $aide->device_user_id]) : __('Not linked') }}</flux:text>
                    </div>
                    <flux:button size="sm" wire:click="enroll({{ $aide->id }})">{{ __('Push to Device') }}</flux:button>
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

    <flux:modal wire:model="showLinkModal" class="max-w-md">
        <form wire:submit="saveDeviceUserLink" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Link to Health Aide') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Device user: :label', ['label' => $linkingDeviceUserLabel]) }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('Health Aide') }}</flux:label>
                <flux:select wire:model="linkHealthAideId" required>
                    <option value="">{{ __('Select health aide…') }}</option>
                    @foreach ($this->healthAides as $aide)
                        <option value="{{ $aide->id }}">
                            {{ $aide->name }}
                            @if ($aide->device_user_id)
                                ({{ __('already linked to :id', ['id' => $aide->device_user_id]) }})
                            @endif
                        </option>
                    @endforeach
                </flux:select>
                <flux:error name="linkHealthAideId" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="cancelLinkDeviceUser">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('OK') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
