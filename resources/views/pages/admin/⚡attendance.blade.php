<?php

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\HealthAide;
use App\Services\AttendanceSyncService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Attendance')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $view = 'users';

    public string $search = '';

    public string $linkFilter = 'all';

    public string $punchDate = '';

    public bool $showDeviceModal = false;

    public ?int $editingDeviceId = null;

    public string $deviceName = '';

    public string $ipAddress = '';

    public int $port = 4370;

    public int $commKey = 0;

    public bool $deviceIsActive = true;

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }

        $this->punchDate = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'search', 'linkFilter', 'punchDate'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AttendanceDevice>
     */
    #[Computed]
    public function devices()
    {
        return AttendanceDevice::query()
            ->withCount(['deviceUsers', 'punches'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, HealthAide>
     */
    #[Computed]
    public function healthAides()
    {
        return HealthAide::query()->orderBy('name')->get(['id', 'name', 'is_active']);
    }

    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, AttendanceDeviceUser>
     */
    #[Computed]
    public function deviceUsers()
    {
        return AttendanceDeviceUser::query()
            ->with(['device:id,name', 'healthAide:id,name'])
            ->withCount('punches')
            ->withMax('punches', 'punched_at')
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('device_user_id', $this->search)
                        ->orWhereHas('healthAide', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when($this->linkFilter === 'linked', fn ($query) => $query->whereNotNull('health_aide_id'))
            ->when($this->linkFilter === 'unlinked', fn ($query) => $query->whereNull('health_aide_id'))
            ->orderBy('attendance_device_id')
            ->orderBy('device_uid')
            ->paginate(25);
    }

    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, AttendancePunch>
     */
    #[Computed]
    public function punches()
    {
        $date = rescue(fn () => CarbonImmutable::parse($this->punchDate), now()->toImmutable(), false);

        return AttendancePunch::query()
            ->with(['device:id,name', 'deviceUser.healthAide:id,name'])
            ->whereBetween('punched_at', [$date->startOfDay(), $date->endOfDay()])
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('device_user_id', $this->search)
                        ->orWhereHas('deviceUser', function ($query) {
                            $query->where('name', 'like', '%'.$this->search.'%')
                                ->orWhereHas('healthAide', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'));
                        });
                });
            })
            ->latest('punched_at')
            ->paginate(50);
    }

    public function syncDevice(int $deviceId, AttendanceSyncService $attendanceSyncService): void
    {
        $device = AttendanceDevice::query()->findOrFail($deviceId);

        try {
            $result = $attendanceSyncService->sync($device);

            Flux::toast(
                variant: 'success',
                text: __(':users users (:newUsers new), :punches new punches imported.', [
                    'users' => $result['users'],
                    'newUsers' => $result['new_users'],
                    'punches' => $result['new_punches'],
                ]),
            );
        } catch (\Throwable $exception) {
            report($exception);

            Flux::toast(variant: 'danger', text: __('Sync failed: :message', ['message' => $exception->getMessage()]));
        }

        unset($this->devices, $this->deviceUsers, $this->punches);
    }

    public function linkHealthAide(int $deviceUserId, string $healthAideId, AttendanceSyncService $attendanceSyncService): void
    {
        $deviceUser = AttendanceDeviceUser::query()->findOrFail($deviceUserId);

        if ($healthAideId === '') {
            $attendanceSyncService->unlink($deviceUser);
            Flux::toast(variant: 'success', text: __('Device user unlinked.'));
        } else {
            $attendanceSyncService->link($deviceUser, HealthAide::query()->findOrFail((int) $healthAideId));
            Flux::toast(variant: 'success', text: __('Linked to health aide.'));
        }

        unset($this->deviceUsers);
    }

    public function openCreateDeviceModal(): void
    {
        $this->resetDeviceForm();
        $this->showDeviceModal = true;
    }

    public function editDevice(int $deviceId): void
    {
        $device = AttendanceDevice::query()->findOrFail($deviceId);

        $this->editingDeviceId = $device->id;
        $this->deviceName = $device->name;
        $this->ipAddress = $device->ip_address;
        $this->port = $device->port;
        $this->commKey = $device->comm_key;
        $this->deviceIsActive = $device->is_active;
        $this->resetValidation();
        $this->showDeviceModal = true;
    }

    public function saveDevice(): void
    {
        $validated = $this->validate([
            'deviceName' => ['required', 'string', 'max:255'],
            'ipAddress' => [
                'required',
                'ip',
                Rule::unique('attendance_devices', 'ip_address')->ignore($this->editingDeviceId),
            ],
            'port' => ['required', 'integer', 'between:1,65535'],
            'commKey' => ['required', 'integer', 'between:0,999999'],
            'deviceIsActive' => ['required', 'boolean'],
        ]);

        $attributes = [
            'name' => $validated['deviceName'],
            'ip_address' => $validated['ipAddress'],
            'port' => $validated['port'],
            'comm_key' => $validated['commKey'],
            'is_active' => $validated['deviceIsActive'],
        ];

        if ($this->editingDeviceId !== null) {
            AttendanceDevice::query()->findOrFail($this->editingDeviceId)->update($attributes);
            Flux::toast(variant: 'success', text: __('Device updated.'));
        } else {
            AttendanceDevice::query()->create($attributes);
            Flux::toast(variant: 'success', text: __('Device added.'));
        }

        $this->showDeviceModal = false;
        $this->resetDeviceForm();
        unset($this->devices);
    }

    public function resetDeviceForm(): void
    {
        $this->editingDeviceId = null;
        $this->deviceName = AttendanceDevice::query()->exists() ? '' : 'Main Entrance K60';
        $this->ipAddress = AttendanceDevice::query()->exists() ? '' : '192.168.100.201';
        $this->port = 4370;
        $this->commKey = 0;
        $this->deviceIsActive = true;
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Attendance') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="openCreateDeviceModal">
            {{ __('Add device') }}
        </flux:button>
    </div>

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Machines') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Device') }}</flux:table.column>
                <flux:table.column>{{ __('Address') }}</flux:table.column>
                <flux:table.column>{{ __('Users / Punches') }}</flux:table.column>
                <flux:table.column>{{ __('Last sync') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->devices as $device)
                    <flux:table.row wire:key="attendance-device-{{ $device->id }}">
                        <flux:table.cell class="font-medium">
                            {{ $device->name }}
                            @unless ($device->is_active)
                                <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Inactive') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm">{{ $device->ip_address }}:{{ $device->port }}</flux:table.cell>
                        <flux:table.cell>{{ $device->device_users_count }} / {{ number_format($device->punches_count) }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($device->last_sync_status === 'failed')
                                <flux:tooltip :content="$device->last_sync_error">
                                    <flux:badge size="sm" color="red">{{ __('Failed') }}</flux:badge>
                                </flux:tooltip>
                            @elseif ($device->last_synced_at)
                                <flux:badge size="sm" color="green">{{ $device->last_synced_at->diffForHumans() }}</flux:badge>
                            @else
                                <flux:text size="sm">{{ __('Never') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                <flux:button
                                    size="sm"
                                    icon="arrow-path"
                                    wire:click="syncDevice({{ $device->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="syncDevice({{ $device->id }})"
                                >
                                    <span wire:loading.remove wire:target="syncDevice({{ $device->id }})">{{ __('Sync now') }}</span>
                                    <span wire:loading wire:target="syncDevice({{ $device->id }})">{{ __('Syncing...') }}</span>
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="editDevice({{ $device->id }})">
                                    {{ __('Edit') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500">
                            {{ __('No attendance machine added yet.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:card>
        <div class="mb-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex gap-2">
                <flux:button
                    size="sm"
                    :variant="$view === 'users' ? 'primary' : 'ghost'"
                    icon="users"
                    wire:click="$set('view', 'users')"
                >
                    {{ __('Machine users') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$view === 'punches' ? 'primary' : 'ghost'"
                    icon="finger-print"
                    wire:click="$set('view', 'punches')"
                >
                    {{ __('Punches') }}
                </flux:button>
            </div>

            <div class="flex flex-col gap-4 sm:flex-row">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search name or ID...') }}"
                    class="w-full sm:w-56"
                />
                @if ($view === 'users')
                    <flux:select wire:model.live="linkFilter" class="w-full sm:w-40">
                        <option value="all">{{ __('All') }}</option>
                        <option value="linked">{{ __('Linked') }}</option>
                        <option value="unlinked">{{ __('Not linked') }}</option>
                    </flux:select>
                @else
                    <flux:input type="date" wire:model.live="punchDate" class="w-full sm:w-44" />
                @endif
            </div>
        </div>

        @if ($view === 'users')
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Machine ID') }}</flux:table.column>
                    <flux:table.column>{{ __('Name on machine') }}</flux:table.column>
                    <flux:table.column>{{ __('Health aide') }}</flux:table.column>
                    <flux:table.column>{{ __('Punches') }}</flux:table.column>
                    <flux:table.column>{{ __('Last punch') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->deviceUsers as $deviceUser)
                        <flux:table.row wire:key="attendance-device-user-{{ $deviceUser->id }}">
                            <flux:table.cell class="font-mono">
                                {{ $deviceUser->device_user_id }}
                                @if ($this->devices->count() > 1)
                                    <flux:text size="sm">{{ $deviceUser->device?->name }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-medium">
                                {{ $deviceUser->name ?? '—' }}
                                @if ($deviceUser->isDeviceAdmin())
                                    <flux:badge size="sm" color="amber" class="ms-1">{{ __('Machine admin') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:select
                                    size="sm"
                                    class="w-56"
                                    wire:key="attendance-link-{{ $deviceUser->id }}-{{ $deviceUser->health_aide_id }}"
                                    wire:change="linkHealthAide({{ $deviceUser->id }}, $event.target.value)"
                                >
                                    <option value="">{{ __('— Not linked —') }}</option>
                                    @foreach ($this->healthAides as $aide)
                                        <option value="{{ $aide->id }}" @selected($deviceUser->health_aide_id === $aide->id)>
                                            {{ $aide->name }}{{ $aide->is_active ? '' : ' ('.__('inactive').')' }}
                                        </option>
                                    @endforeach
                                </flux:select>
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($deviceUser->punches_count) }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $deviceUser->punches_max_punched_at ? \Illuminate\Support\Carbon::parse($deviceUser->punches_max_punched_at)->format('d M Y, h:i A') : '—' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No machine users imported yet. Press "Sync now" on a device.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->deviceUsers->links() }}
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Time') }}</flux:table.column>
                    <flux:table.column>{{ __('Staff') }}</flux:table.column>
                    <flux:table.column>{{ __('Machine ID') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Verified by') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->punches as $punch)
                        <flux:table.row wire:key="attendance-punch-{{ $punch->id }}">
                            <flux:table.cell class="whitespace-nowrap">{{ $punch->punched_at->format('h:i:s A') }}</flux:table.cell>
                            <flux:table.cell class="font-medium">
                                @if ($punch->deviceUser?->healthAide)
                                    {{ $punch->deviceUser->healthAide->name }}
                                @else
                                    {{ $punch->deviceUser?->name ?? __('Unknown') }}
                                    <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Not linked') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $punch->device_user_id }}</flux:table.cell>
                            <flux:table.cell>{{ $punch->punchStateLabel() }}</flux:table.cell>
                            <flux:table.cell>{{ $punch->verifyTypeLabel() }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No punches on this day.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->punches->links() }}
            </div>
        @endif
    </flux:card>

    <flux:modal wire:model="showDeviceModal" class="max-w-md">
        <form wire:submit="saveDevice" class="space-y-4">
            <flux:heading size="lg">
                {{ $editingDeviceId ? __('Edit device') : __('Add device') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="deviceName" />
                <flux:error name="deviceName" />
            </flux:field>

            <div class="grid grid-cols-3 gap-4">
                <flux:field class="col-span-2">
                    <flux:label>{{ __('IP address') }}</flux:label>
                    <flux:input wire:model="ipAddress" placeholder="192.168.100.201" />
                    <flux:error name="ipAddress" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Port') }}</flux:label>
                    <flux:input type="number" wire:model="port" />
                    <flux:error name="port" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Comm key') }}</flux:label>
                <flux:input type="number" wire:model="commKey" />
                <flux:description>{{ __('Communication password set on the machine. Leave 0 if none.') }}</flux:description>
                <flux:error name="commKey" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Active (auto-sync every 10 minutes)') }}</flux:label>
                <flux:switch wire:model="deviceIsActive" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showDeviceModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
