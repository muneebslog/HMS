<?php

use App\Enums\PunchPairingRole;
use App\Models\AttendancePunch;
use App\Models\AttendanceWorkSession;
use App\Models\HealthAide;
use App\Services\AttendanceProcessingService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Attendance Punches')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $search = '';

    public ?int $healthAideId = null;

    public bool $showManualModal = false;

    public ?int $manualHealthAideId = null;

    public string $manualPunchedAt = '';

    public string $manualRole = '';

    public string $manualNotes = '';

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }

        $this->dateFrom = $this->dateFrom !== '' ? $this->dateFrom : today()->toDateString();
        $this->dateTo = $this->dateTo !== '' ? $this->dateTo : today()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedHealthAideId(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function healthAides()
    {
        return HealthAide::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function punches()
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        return AttendancePunch::query()
            ->with(['healthAide', 'device', 'workSessionAsIn', 'workSessionAsOut'])
            ->whereBetween('punched_at', [$from, $to])
            ->when($this->healthAideId, fn ($query) => $query->where('health_aide_id', $this->healthAideId))
            ->when($this->search !== '', function ($query) {
                $query->where(function ($inner) {
                    $inner->where('device_user_id', 'like', '%'.$this->search.'%')
                        ->orWhereHas('healthAide', fn ($aide) => $aide->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->orderBy('punched_at')
            ->orderBy('id')
            ->paginate(50);
    }

    #[Computed]
    public function effectiveRoles(): array
    {
        $roles = [];
        $expectInByAide = [];

        $from = Carbon::parse($this->dateFrom)->startOfDay()->subDays(2);
        $to = Carbon::parse($this->dateTo)->endOfDay()->addDays(2);

        $history = AttendancePunch::query()
            ->whereNotNull('health_aide_id')
            ->whereBetween('punched_at', [$from, $to])
            ->when($this->healthAideId, fn ($query) => $query->where('health_aide_id', $this->healthAideId))
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();

        foreach ($history as $punch) {
            if ($punch->pairing_role === PunchPairingRole::Ignore) {
                $roles[$punch->id] = PunchPairingRole::Ignore;

                continue;
            }

            $aideId = $punch->health_aide_id;
            $expectIn = $expectInByAide[$aideId] ?? true;

            if ($punch->pairing_role !== null) {
                $role = $punch->pairing_role;
            } else {
                $role = $expectIn ? PunchPairingRole::In : PunchPairingRole::Out;
            }

            $roles[$punch->id] = $role;

            if ($role === PunchPairingRole::In) {
                $expectInByAide[$aideId] = false;
            } elseif ($role === PunchPairingRole::Out) {
                $expectInByAide[$aideId] = true;
            }
        }

        return $roles;
    }

    public function setRole(int $punchId, string $role, AttendanceProcessingService $processingService): void
    {
        $punch = AttendancePunch::query()->findOrFail($punchId);

        $pairingRole = $role === 'auto' ? null : PunchPairingRole::from($role);

        $processingService->setPunchPairingRole($punch, $pairingRole);

        unset($this->punches, $this->effectiveRoles);
        Flux::toast(variant: 'success', text: __('Punch role updated.'));
    }

    public function confirmSession(int $sessionId, AttendanceProcessingService $processingService): void
    {
        $session = AttendanceWorkSession::query()->findOrFail($sessionId);
        $processingService->confirmSession($session, auth()->user());

        unset($this->punches, $this->effectiveRoles);
        Flux::toast(variant: 'success', text: __('Session confirmed.'));
    }

    public function unconfirmSession(int $sessionId, AttendanceProcessingService $processingService): void
    {
        $session = AttendanceWorkSession::query()->findOrFail($sessionId);
        $processingService->unconfirmSession($session);
        $processingService->rebuildSessionsForAide($session->health_aide_id);

        unset($this->punches, $this->effectiveRoles);
        Flux::toast(variant: 'success', text: __('Session unconfirmed.'));
    }

    public function confirmAllSuggested(AttendanceProcessingService $processingService): void
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        $sessions = AttendanceWorkSession::query()
            ->suggested()
            ->whereBetween('starts_at', [$from, $to])
            ->when($this->healthAideId, fn ($query) => $query->where('health_aide_id', $this->healthAideId))
            ->get();

        foreach ($sessions as $session) {
            $processingService->confirmSession($session, auth()->user());
        }

        unset($this->punches, $this->effectiveRoles);
        Flux::toast(variant: 'success', text: __('Confirmed :count session(s).', ['count' => $sessions->count()]));
    }

    public function rebuild(AttendanceProcessingService $processingService): void
    {
        $processingService->rebuildRecentSessions(Carbon::parse($this->dateFrom)->subDays(2)->startOfDay());

        unset($this->punches, $this->effectiveRoles);
        Flux::toast(variant: 'success', text: __('Sessions rebuilt.'));
    }

    public function openManualModal(): void
    {
        $this->manualHealthAideId = $this->healthAideId;
        $this->manualPunchedAt = now()->format('Y-m-d\\TH:i');
        $this->manualRole = '';
        $this->manualNotes = '';
        $this->showManualModal = true;
    }

    public function saveManualPunch(AttendanceProcessingService $processingService): void
    {
        $validated = $this->validate([
            'manualHealthAideId' => ['required', 'exists:health_aides,id'],
            'manualPunchedAt' => ['required', 'date'],
            'manualRole' => ['nullable', Rule::in(['', ...array_column(PunchPairingRole::cases(), 'value')])],
            'manualNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $aide = HealthAide::query()->findOrFail($validated['manualHealthAideId']);
        $role = ($validated['manualRole'] ?? '') !== ''
            ? PunchPairingRole::from($validated['manualRole'])
            : null;

        $processingService->createManualPunch(
            $aide,
            Carbon::parse($validated['manualPunchedAt']),
            $role,
            auth()->user(),
            $validated['manualNotes'] ?: null,
        );

        $this->showManualModal = false;
        unset($this->punches, $this->effectiveRoles);
        Flux::toast(variant: 'success', text: __('Manual punch added.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Attendance Punches') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="rebuild" icon="arrow-path">{{ __('Rebuild Pairs') }}</flux:button>
            <flux:button wire:click="confirmAllSuggested" variant="filled" icon="check">{{ __('Confirm All Suggested') }}</flux:button>
            <flux:button variant="primary" wire:click="openManualModal" icon="plus">{{ __('Add Punch') }}</flux:button>
        </div>
    </div>

    <flux:card>
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input wire:model.live="dateFrom" type="date" label="{{ __('From') }}" />
            <flux:input wire:model.live="dateTo" type="date" label="{{ __('To') }}" />
            <flux:select wire:model.live="healthAideId" label="{{ __('Health Aide') }}">
                <option value="">{{ __('All aides') }}</option>
                @foreach ($this->healthAides as $aide)
                    <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.300ms="search" label="{{ __('Search') }}" placeholder="{{ __('Aide or device user ID...') }}" />
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Health Aide') }}</flux:table.column>
                <flux:table.column>{{ __('Punched At') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
                <flux:table.column>{{ __('Session') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->punches as $punch)
                    @php
                        $effectiveRole = $this->effectiveRoles[$punch->id] ?? null;
                        $session = $punch->workSessionAsIn ?? $punch->workSessionAsOut;
                    @endphp
                    <flux:table.row wire:key="punch-log-{{ $punch->id }}">
                        <flux:table.cell>{{ $punch->healthAide?->name ?? __('Unmapped') }}</flux:table.cell>
                        <flux:table.cell>{{ $punch->punched_at->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($effectiveRole)
                                <flux:badge size="sm" :color="$effectiveRole->badgeColor()">
                                    {{ $effectiveRole->label() }}
                                    @if ($punch->pairing_role === null && $effectiveRole !== \App\Enums\PunchPairingRole::Ignore)
                                        ({{ __('auto') }})
                                    @endif
                                </flux:badge>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $punch->punch_state_source?->value ?? __('device') }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($session)
                                <flux:badge size="sm" :color="$session->status->badgeColor()">{{ $session->status->label() }}</flux:badge>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                <flux:button size="xs" wire:click="setRole({{ $punch->id }}, 'in')">{{ __('In') }}</flux:button>
                                <flux:button size="xs" wire:click="setRole({{ $punch->id }}, 'out')">{{ __('Out') }}</flux:button>
                                <flux:button size="xs" wire:click="setRole({{ $punch->id }}, 'ignore')">{{ __('Ignore') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="setRole({{ $punch->id }}, 'auto')">{{ __('Auto') }}</flux:button>
                                @if ($session?->status === \App\Enums\WorkSessionStatus::Suggested && $punch->workSessionAsIn)
                                    <flux:button size="xs" variant="primary" wire:click="confirmSession({{ $session->id }})">{{ __('Confirm') }}</flux:button>
                                @endif
                                @if ($session?->status === \App\Enums\WorkSessionStatus::Confirmed && $punch->workSessionAsIn)
                                    <flux:button size="xs" variant="ghost" wire:click="unconfirmSession({{ $session->id }})">{{ __('Unconfirm') }}</flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="6">{{ __('No punches found.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $this->punches->links() }}
    </flux:card>

    <flux:modal wire:model="showManualModal" class="md:w-lg">
        <flux:heading size="lg">{{ __('Add Manual Punch') }}</flux:heading>
        <form wire:submit="saveManualPunch" class="mt-4 space-y-4">
            <flux:select wire:model="manualHealthAideId" label="{{ __('Health Aide') }}" required>
                <option value="">{{ __('Select aide') }}</option>
                @foreach ($this->healthAides as $aide)
                    <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="manualPunchedAt" type="datetime-local" label="{{ __('Punched At') }}" required />
            <flux:select wire:model="manualRole" label="{{ __('Role') }}">
                <option value="">{{ __('Auto (pair by order)') }}</option>
                @foreach (\App\Enums\PunchPairingRole::cases() as $role)
                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="manualNotes" label="{{ __('Notes') }}" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showManualModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
