<?php

use App\Enums\LeaveKind;
use App\Models\AttendanceDayReview;
use App\Models\AttendancePunch;
use App\Models\DutySession;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\StaffLeave;
use App\Services\DutyPairingService;
use App\Services\StaffLeaveService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Pairing Desk')] class extends Component
{
    /** Hours shown on each timeline, starting at midnight of the duty date. */
    public const TimelineHours = 36;

    #[Url]
    public string $date = '';

    #[Url]
    public string $filter = 'all';

    public ?int $editingEmployeeId = null;

    /**
     * Sessions being edited for one person before they are confirmed.
     *
     * @var list<array{starts_at: string, ends_at: string, in_punch_id: ?int, out_punch_id: ?int, in_time: ?string, out_time: ?string, break_minutes: int, duty_template_ids: list<string>, notes: string}>
     */
    public array $drafts = [];

    public function mount(): void
    {
        if (! rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d', $this->date), null, false)) {
            $this->date = today()->subDay()->toDateString();
        }
    }

    public function updatedDate(): void
    {
        $this->closeEditor();
    }

    #[Computed]
    public function day(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date)->startOfDay();
    }

    /**
     * @return Collection<int, array{employee: Employee, punches: Collection<int, AttendancePunch>, confirmed: Collection<int, DutySession>, suggestions: list<array<string, mixed>>, status: string}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $pairing = app(DutyPairingService::class);
        $leaves = StaffLeave::query()->whereDate('leave_date', $this->day)->get()->keyBy('employee_id');

        return $pairing->day($this->day)->map(function (array $row) use ($pairing, $leaves): array {
            $row['leave'] = $leaves->get($row['employee']->id);
            $row['status'] = match (true) {
                collect($row['suggestions'])->contains(fn (array $suggestion): bool => $pairing->needsAttention($suggestion)) => 'attention',
                $row['suggestions'] !== [] => 'suggested',
                $row['confirmed']->isNotEmpty() => 'confirmed',
                $row['leave'] !== null => 'leave',
                default => 'none',
            };

            return $row;
        });
    }

    #[Computed]
    public function holiday(): ?Holiday
    {
        return Holiday::query()->whereDate('holiday_date', $this->day)->first();
    }

    /**
     * Record a full day off for someone with no punches on this date.
     */
    public function recordDayOff(int $employeeId, string $kind, StaffLeaveService $leaves): void
    {
        try {
            $created = $leaves->record(
                Employee::query()->findOrFail($employeeId),
                $this->day,
                $this->day,
                LeaveKind::from($kind),
                false,
                __('From the pairing desk'),
                false,
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());

            return;
        }

        $this->refreshDay();

        $unpaid = $created->sum(fn (StaffLeave $leave): float => $leave->unpaidDays());

        Flux::toast(variant: $unpaid > 0 ? 'warning' : 'success', text: $unpaid > 0 ? __('Saved — this day will be deducted.') : __('Saved as paid leave.'));
    }

    public function removeDayOff(int $leaveId, StaffLeaveService $leaves): void
    {
        $leaves->delete(StaffLeave::query()->findOrFail($leaveId));
        $this->refreshDay();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function visibleRows(): Collection
    {
        return $this->rows
            ->when($this->filter !== 'all', fn (Collection $rows) => $rows->where('status', $this->filter))
            ->sortBy(fn (array $row): string => ['attention' => 0, 'suggested' => 1, 'none' => 2, 'confirmed' => 3, 'leave' => 4][$row['status']].$row['employee']->name);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return ['all' => $this->rows->count()] + $this->rows->countBy('status')->all();
    }

    #[Computed]
    public function review(): ?AttendanceDayReview
    {
        return AttendanceDayReview::query()->with('reviewer:id,name')->whereDate('review_date', $this->day)->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ShiftTemplate>
     */
    #[Computed]
    public function templates()
    {
        return app(DutyPairingService::class)->templates();
    }

    public function previousDay(): void
    {
        $this->date = $this->day->subDay()->toDateString();
        $this->closeEditor();
    }

    public function nextDay(): void
    {
        $this->date = $this->day->addDay()->toDateString();
        $this->closeEditor();
    }

    public function confirmEmployee(int $employeeId, DutyPairingService $pairing): void
    {
        $row = $this->rows->get($employeeId);

        if ($row === null) {
            return;
        }

        $confirmed = 0;

        foreach ($row['suggestions'] as $suggestion) {
            if (! $pairing->needsAttention($suggestion)) {
                $pairing->confirmSuggestion($row['employee'], $suggestion, auth()->user());
                $confirmed++;
            }
        }

        $this->refreshDay();

        Flux::toast(variant: 'success', text: trans_choice('{0} Nothing to confirm.|{1} 1 session confirmed.|[2,*] :count sessions confirmed.', $confirmed));
    }

    public function confirmAllClean(DutyPairingService $pairing): void
    {
        $confirmed = $pairing->confirmCleanSuggestions($this->day, auth()->user());
        $this->refreshDay();

        Flux::toast(variant: 'success', text: trans_choice('{0} Nothing to confirm.|{1} 1 session confirmed.|[2,*] :count sessions confirmed.', $confirmed));
    }

    public function markReviewed(DutyPairingService $pairing): void
    {
        try {
            $pairing->markReviewed($this->day, auth()->user());
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());

            return;
        }

        unset($this->review);

        Flux::toast(variant: 'success', text: __(':date marked as reviewed.', ['date' => $this->day->format('D j M')]));
    }

    public function unconfirmSession(int $sessionId, DutyPairingService $pairing): void
    {
        $pairing->unconfirm(DutySession::query()->findOrFail($sessionId));
        $this->refreshDay();

        Flux::toast(text: __('Session sent back for pairing.'));
    }

    public function openEditor(int $employeeId): void
    {
        $row = $this->rows->get($employeeId);

        if ($row === null) {
            return;
        }

        $this->editingEmployeeId = $employeeId;
        $this->drafts = array_map(fn (array $suggestion): array => [
            'starts_at' => $suggestion['starts_at']->format('Y-m-d\TH:i'),
            'ends_at' => $suggestion['ends_at']?->format('Y-m-d\TH:i') ?? '',
            'in_punch_id' => $suggestion['in_punch_id'],
            'out_punch_id' => $suggestion['out_punch_id'],
            'in_time' => $suggestion['starts_at']->format('Y-m-d\TH:i'),
            'out_time' => $suggestion['ends_at']?->format('Y-m-d\TH:i'),
            'break_minutes' => $suggestion['break_minutes'],
            'duty_template_ids' => array_map(fn (?int $id): string => (string) $id, $suggestion['duty_template_ids'] ?: [null]),
            'notes' => '',
        ], $row['suggestions']);

        $this->resetValidation();
    }

    public function closeEditor(): void
    {
        $this->editingEmployeeId = null;
        $this->drafts = [];
        $this->resetValidation();
    }

    public function addDraft(): void
    {
        $this->drafts[] = [
            'starts_at' => $this->day->setTime(8, 0)->format('Y-m-d\TH:i'),
            'ends_at' => $this->day->setTime(15, 0)->format('Y-m-d\TH:i'),
            'in_punch_id' => null,
            'out_punch_id' => null,
            'in_time' => null,
            'out_time' => null,
            'break_minutes' => 0,
            'duty_template_ids' => [''],
            'notes' => '',
        ];
    }

    public function removeDraft(int $index): void
    {
        unset($this->drafts[$index]);
        $this->drafts = array_values($this->drafts);
    }

    public function addDuty(int $index): void
    {
        $this->drafts[$index]['duty_template_ids'][] = (string) ($this->templates->first()?->id ?? '');
    }

    public function removeDuty(int $index, int $dutyIndex): void
    {
        unset($this->drafts[$index]['duty_template_ids'][$dutyIndex]);
        $this->drafts[$index]['duty_template_ids'] = array_values($this->drafts[$index]['duty_template_ids']) ?: [''];
    }

    /**
     * Fill the shifts for a draft from its current times.
     */
    public function suggestDuties(int $index, DutyPairingService $pairing): void
    {
        $times = $this->draftTimes($this->drafts[$index] ?? []);

        if ($times === null || $this->editingEmployeeId === null) {
            return;
        }

        $ids = $pairing->planDuties(Employee::query()->findOrFail($this->editingEmployeeId), ...$times);
        $this->drafts[$index]['duty_template_ids'] = array_map(fn (?int $id): string => (string) $id, $ids);
    }

    public function ignorePunch(int $punchId, DutyPairingService $pairing): void
    {
        $pairing->ignorePunch($this->ownPunch($punchId), auth()->user(), __('Set aside on the pairing desk'));
        $this->reopenEditor();
    }

    public function restorePunch(int $punchId, DutyPairingService $pairing): void
    {
        $pairing->restorePunch($this->ownPunch($punchId));
        $this->reopenEditor();
    }

    public function saveDraft(int $index, DutyPairingService $pairing): void
    {
        $draft = $this->drafts[$index] ?? null;

        if ($draft === null || $this->editingEmployeeId === null) {
            return;
        }

        $this->resetValidation();
        $times = $this->draftTimes($draft);

        if ($times === null) {
            $this->addError("drafts.$index.ends_at", __('Enter both arriving and leaving times.'));

            return;
        }

        $startManual = $draft['in_punch_id'] === null || $draft['starts_at'] !== $draft['in_time'];
        $endManual = $draft['out_punch_id'] === null || $draft['ends_at'] !== $draft['out_time'];

        if (($startManual || $endManual) && blank($draft['notes'])) {
            $this->addError("drafts.$index.notes", __('Say why the time was typed in (e.g. forgot to punch out).'));

            return;
        }

        try {
            $pairing->confirm(Employee::query()->findOrFail($this->editingEmployeeId), [
                'starts_at' => $times[0],
                'ends_at' => $times[1],
                'in_punch_id' => $startManual ? null : $draft['in_punch_id'],
                'out_punch_id' => $endManual ? null : $draft['out_punch_id'],
                'break_minutes' => (int) $draft['break_minutes'],
                'notes' => filled($draft['notes']) ? $draft['notes'] : null,
                'duty_template_ids' => array_map(fn (string $id): ?int => $id === '' ? null : (int) $id, $draft['duty_template_ids']),
            ], auth()->user());
        } catch (ValidationException $exception) {
            $this->addError("drafts.$index.ends_at", collect($exception->errors())->flatten()->first());

            return;
        }

        $this->removeDraft($index);
        $this->refreshDay();

        if ($this->drafts === []) {
            $this->closeEditor();
        }

        Flux::toast(variant: 'success', text: __('Session confirmed.'));
    }

    /**
     * Duties a draft would produce, for the live preview.
     *
     * @return list<array<string, mixed>>
     */
    public function draftDuties(int $index): array
    {
        $draft = $this->drafts[$index] ?? null;
        $times = $draft ? $this->draftTimes($draft) : null;

        if ($times === null) {
            return [];
        }

        return app(DutyPairingService::class)->buildDuties(
            $times[0],
            $times[1],
            array_map(fn (string $id): ?int => $id === '' ? null : (int) $id, $draft['duty_template_ids']),
        );
    }

    /**
     * Position of a stretch of time on the 36 hour timeline, as CSS percentages.
     *
     * @return array{left: string, width: string}|null
     */
    public function bar(CarbonImmutable $start, ?CarbonImmutable $end): ?array
    {
        $windowMinutes = self::TimelineHours * 60;
        $from = (int) $this->day->diffInMinutes($start, false);
        $to = $end ? (int) $this->day->diffInMinutes($end, false) : $from + 90;

        $from = max(0, $from);
        $to = min($windowMinutes, $to);

        if ($to <= $from) {
            return null;
        }

        return [
            'left' => round($from / $windowMinutes * 100, 2).'%',
            'width' => max(1.5, round(($to - $from) / $windowMinutes * 100, 2)).'%',
        ];
    }

    /**
     * Punches synced after the day was marked reviewed.
     */
    #[Computed]
    public function punchesSinceReview(): int
    {
        if ($this->review === null) {
            return 0;
        }

        return AttendancePunch::query()
            ->whereBetween('punched_at', [$this->day, $this->day->endOfDay()])
            ->where('created_at', '>', $this->review->reviewed_at)
            ->count();
    }

    public function flagLabel(string $flag): string
    {
        return match ($flag) {
            'missing_out' => __('Missing out punch'),
            'long' => __('Very long — check for double duty'),
            'break' => __('Went out in between'),
            default => $flag,
        };
    }

    public function minutesLabel(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m' : $minutes.'m';
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function draftTimes(array $draft): ?array
    {
        $start = rescue(fn () => filled($draft['starts_at'] ?? null) ? CarbonImmutable::parse($draft['starts_at']) : null, null, false);
        $end = rescue(fn () => filled($draft['ends_at'] ?? null) ? CarbonImmutable::parse($draft['ends_at']) : null, null, false);

        return $start && $end && $end->gt($start) ? [$start, $end] : null;
    }

    private function ownPunch(int $punchId): AttendancePunch
    {
        return AttendancePunch::query()
            ->whereKey($punchId)
            ->whereIn('attendance_device_user_id', Employee::query()->findOrFail($this->editingEmployeeId)->attendanceDeviceUsers()->select('id'))
            ->firstOrFail();
    }

    private function reopenEditor(): void
    {
        $employeeId = $this->editingEmployeeId;
        $this->refreshDay();

        if ($employeeId !== null) {
            $this->openEditor($employeeId);
        }
    }

    private function refreshDay(): void
    {
        unset($this->rows, $this->visibleRows, $this->counts, $this->review, $this->punchesSinceReview, $this->holiday);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-hr-nav current="hr.pairing" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Pairing desk') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Confirm who worked which duty. A duty belongs to the day it started.') }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            <flux:button icon="chevron-left" wire:click="previousDay" :aria-label="__('Previous day')" />
            <flux:input type="date" wire:model.live="date" class="w-44" :aria-label="__('Duty date')" />
            <flux:button icon="chevron-right" wire:click="nextDay" :aria-label="__('Next day')" />
        </div>
    </div>

    @php
        $done = ($this->counts['confirmed'] ?? 0) + ($this->counts['leave'] ?? 0) + ($this->counts['none'] ?? 0);
        $total = max(1, $this->counts['all']);
    @endphp

    <flux:card class="flex flex-col gap-4 lg:flex-row lg:items-center">
        <div class="min-w-0 flex-1">
            <div class="flex justify-between gap-4">
                <flux:heading>{{ $this->day->format('l j M Y') }} — {{ __(':done of :total staff done', ['done' => $done, 'total' => $this->counts['all']]) }}</flux:heading>
                <flux:text>{{ round($done / $total * 100) }}%</flux:text>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                <div class="h-full bg-green-600" style="width: {{ round($done / $total * 100) }}%"></div>
            </div>

            @if ($this->holiday)
                <flux:text class="mt-2 font-medium text-violet-700 dark:text-violet-400">{{ __('Public holiday: :name. Nobody is marked absent today.', ['name' => $this->holiday->name]) }}</flux:text>
            @endif

            @if ($this->review)
                <flux:text class="mt-2 text-green-700 dark:text-green-400">
                    {{ __('Reviewed by :name on :at', ['name' => $this->review->reviewer?->name ?? __('someone'), 'at' => $this->review->reviewed_at->format('j M, h:i A')]) }}
                    @if ($this->punchesSinceReview > 0)
                        <span class="text-red-700 dark:text-red-400">· {{ trans_choice('{1} 1 punch arrived after review|[2,*] :count punches arrived after review', $this->punchesSinceReview) }}</span>
                    @endif
                </flux:text>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" wire:click="confirmAllClean" wire:loading.attr="disabled">
                {{ __('Confirm all clean (:count)', ['count' => $this->counts['suggested'] ?? 0]) }}
            </flux:button>
            <flux:button size="sm" variant="primary" icon="check-badge" wire:click="markReviewed">
                {{ __('Mark day reviewed') }}
            </flux:button>
        </div>
    </flux:card>

    <div class="flex flex-wrap gap-2">
        @foreach (['all' => __('All'), 'attention' => __('Need attention'), 'suggested' => __('Suggested'), 'confirmed' => __('Confirmed'), 'none' => __('No punches'), 'leave' => __('On leave')] as $value => $label)
            <flux:button
                wire:key="filter-{{ $value }}"
                size="sm"
                :variant="$filter === $value ? 'primary' : 'outline'"
                wire:click="$set('filter', '{{ $value }}')"
            >
                {{ $label }} ({{ $this->counts[$value] ?? 0 }})
            </flux:button>
        @endforeach
    </div>

    <flux:card class="overflow-x-auto p-0!">
        <div class="min-w-[1000px]">
            <div class="grid grid-cols-[200px_minmax(0,1fr)_220px] gap-4 border-b border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-500 dark:border-zinc-700">
                <span>{{ __('STAFF') }}</span>
                <div class="relative h-4">
                    @foreach ([0, 6, 12, 18, 24, 30, 36] as $hour)
                        <span wire:key="tick-{{ $hour }}" class="absolute -translate-x-1/2 whitespace-nowrap" style="left: {{ round($hour / 36 * 100, 2) }}%">
                            {{ $this->day->addHours($hour)->format($hour === 24 ? 'D H:i' : 'H:i') }}
                        </span>
                    @endforeach
                </div>
                <span>{{ __('DUTY · STATUS') }}</span>
            </div>

            @forelse ($this->visibleRows as $row)
                @php
                    $employee = $row['employee'];
                    $isEditing = $editingEmployeeId === $employee->id;
                @endphp
                <div wire:key="pairing-row-{{ $employee->id }}" @class(['border-b border-zinc-100 dark:border-zinc-700/60', 'bg-amber-50 dark:bg-amber-950/20' => $row['status'] === 'attention'])>
                    <div class="grid grid-cols-[200px_minmax(0,1fr)_220px] items-center gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('admin.employees.profile', $employee) }}" wire:navigate class="block truncate font-medium hover:underline">{{ $employee->name }}</a>
                            <flux:badge size="sm" :color="$employee->staff_category->color()">{{ $employee->staff_category->label() }}</flux:badge>
                        </div>

                        <div class="relative h-10 rounded-md border border-zinc-100 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="absolute inset-y-0 border-l-2 border-dashed border-zinc-400" style="left: {{ round(24 / 36 * 100, 2) }}%" aria-hidden="true"></div>

                            @foreach ($row['confirmed'] as $session)
                                @php $position = $this->bar($session->starts_at, $session->ends_at); @endphp
                                @if ($position)
                                    <div wire:key="bar-session-{{ $session->id }}" class="absolute top-2 flex h-6 items-center justify-between overflow-hidden whitespace-nowrap rounded bg-blue-700 px-1.5 text-[11px] font-semibold text-white" style="left: {{ $position['left'] }}; width: {{ $position['width'] }}">
                                        <span>{{ $session->starts_at->format('H:i') }}</span><span>{{ $session->ends_at->format('H:i') }}</span>
                                    </div>
                                @endif
                            @endforeach

                            @foreach ($row['suggestions'] as $suggestionIndex => $suggestion)
                                @php $position = $this->bar($suggestion['starts_at'], $suggestion['ends_at']); @endphp
                                @if ($position)
                                    <div wire:key="bar-suggestion-{{ $employee->id }}-{{ $suggestionIndex }}" @class([
                                        'absolute top-2 flex h-6 items-center justify-between overflow-hidden whitespace-nowrap rounded px-1.5 text-[11px] font-semibold',
                                        'border-2 border-dashed border-red-600 bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300' => $suggestion['ends_at'] === null,
                                        'bg-blue-200 text-blue-900 dark:bg-blue-900/60 dark:text-blue-100' => $suggestion['ends_at'] !== null,
                                    ]) style="left: {{ $position['left'] }}; width: {{ $position['width'] }}">
                                        <span>{{ $suggestion['starts_at']->format('H:i') }}</span><span>{{ $suggestion['ends_at']?->format('H:i') ?? '?' }}</span>
                                    </div>
                                @endif
                            @endforeach

                            @if ($row['status'] === 'none')
                                <flux:text size="sm" class="absolute left-3 top-2.5">{{ __('No punches') }}</flux:text>
                            @elseif ($row['status'] === 'leave')
                                <flux:text size="sm" class="absolute left-3 top-2.5">{{ $row['leave']->kind->label() }}{{ $row['leave']->reason ? ' — '.$row['leave']->reason : '' }}</flux:text>
                            @endif
                        </div>

                        <div class="flex flex-col items-start gap-1">
                            <div class="flex flex-wrap gap-1">
                                @php
                                    $dutyCount = $row['confirmed']->sum(fn ($session) => $session->duties->count())
                                        + collect($row['suggestions'])->sum(fn ($suggestion) => count($suggestion['duties']));
                                @endphp
                                @if ($dutyCount > 0)
                                    <flux:badge size="sm">{{ trans_choice('{1} 1 duty|[2,*] :count duties', $dutyCount) }}</flux:badge>
                                @endif
                                @switch($row['status'])
                                    @case('attention')
                                        <flux:badge size="sm" color="red">{{ __('Needs attention') }}</flux:badge>
                                        @break
                                    @case('suggested')
                                        <flux:badge size="sm" color="sky">{{ __('Suggested') }}</flux:badge>
                                        @break
                                    @case('confirmed')
                                        <flux:badge size="sm" color="green" icon="check">{{ __('Confirmed') }}</flux:badge>
                                        @break
                                @endswitch
                                @if ($row['leave'])
                                    <flux:badge size="sm" :color="$row['leave']->unpaidDays() > 0 ? 'amber' : 'green'">
                                        {{ $row['leave']->isHalfDay() ? __('½ day leave') : ($row['leave']->kind === \App\Enums\LeaveKind::Uninformed ? __('Absent') : __('On leave')) }}
                                        · {{ $row['leave']->unpaidDays() > 0 ? __('deducted') : __('paid') }}
                                    </flux:badge>
                                @endif
                            </div>

                            @foreach (collect($row['suggestions'])->pluck('flags')->flatten()->unique() as $flag)
                                <flux:text size="sm" @class(['text-red-700 dark:text-red-400' => $flag !== 'break'])>{{ $this->flagLabel($flag) }}</flux:text>
                            @endforeach

                            @foreach (collect($row['suggestions'])->pluck('duties')->flatten(1)->merge($row['confirmed']->flatMap->duties) as $duty)
                                @php $late = is_array($duty) ? $duty['late_minutes'] : $duty->late_minutes; @endphp
                                @if ($late > \App\Models\AppSetting::attendanceRule(\App\Models\AppSetting::LateGraceMinutes))
                                    <flux:text size="sm" class="text-amber-700 dark:text-amber-400">{{ __('Late :time', ['time' => $this->minutesLabel($late)]) }}</flux:text>
                                @endif
                            @endforeach

                            <div class="flex flex-wrap gap-1">
                                @if ($row['status'] === 'none' && ! $this->holiday)
                                    <flux:button size="xs" variant="danger" wire:click="recordDayOff({{ $employee->id }}, 'uninformed')">{{ __('Absent') }}</flux:button>
                                    <flux:button size="xs" wire:click="recordDayOff({{ $employee->id }}, 'informed')">{{ __('Informed leave') }}</flux:button>
                                @endif
                                @if ($row['status'] === 'leave')
                                    <flux:button size="xs" variant="ghost" wire:click="removeDayOff({{ $row['leave']->id }})" wire:confirm="{{ __('Remove this leave?') }}">{{ __('Remove leave') }}</flux:button>
                                @endif
                                @if ($row['status'] === 'suggested')
                                    <flux:button size="xs" variant="primary" wire:click="confirmEmployee({{ $employee->id }})">{{ __('Confirm') }}</flux:button>
                                @endif
                                @if ($isEditing)
                                    <flux:button size="xs" variant="ghost" wire:click="closeEditor">{{ __('Close') }}</flux:button>
                                @else
                                    <flux:button size="xs" variant="ghost" wire:click="openEditor({{ $employee->id }})">{{ $row['status'] === 'none' ? __('Add manually') : __('Review') }}</flux:button>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($isEditing)
                        <div class="mx-4 mb-4 grid gap-6 rounded-xl border border-amber-300 bg-white p-4 lg:grid-cols-[300px_minmax(0,1fr)] dark:border-amber-700 dark:bg-zinc-900">
                            <div class="flex flex-col gap-2">
                                <flux:heading size="sm">{{ __('Machine punches') }}</flux:heading>
                                <flux:text size="sm">{{ __(':from → :to', ['from' => $this->day->subDay()->format('D H:i'), 'to' => app(\App\Services\DutyPairingService::class)->window($this->day)[1]->format('D H:i')]) }}</flux:text>

                                @php
                                    $draftPunchIds = collect($drafts)->flatMap(fn ($draft) => [$draft['in_punch_id'], $draft['out_punch_id']])->filter()->all();
                                    $duplicateIds = collect($row['suggestions'])->pluck('duplicate_punch_ids')->flatten()->all();
                                    $confirmedPunchIds = $row['confirmed']->flatMap(fn ($session) => [$session->in_punch_id, $session->out_punch_id])->filter()->all();
                                @endphp

                                @forelse ($row['punches'] as $punch)
                                    <div wire:key="punch-{{ $punch->id }}" class="flex items-center justify-between gap-2">
                                        <span @class(['font-mono text-sm', 'text-zinc-400 line-through' => $punch->isIgnored()])>{{ $punch->punched_at->format('D H:i:s') }}</span>
                                        <div class="flex items-center gap-1">
                                            @if ($punch->isIgnored())
                                                <flux:badge size="sm">{{ __('Ignored') }}</flux:badge>
                                                <flux:button size="xs" variant="ghost" wire:click="restorePunch({{ $punch->id }})">{{ __('Restore') }}</flux:button>
                                            @elseif (in_array($punch->id, $confirmedPunchIds, true))
                                                <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                                            @elseif (in_array($punch->id, $draftPunchIds, true))
                                                <flux:badge size="sm" color="sky">{{ __('Paired') }}</flux:badge>
                                            @elseif (in_array($punch->id, $duplicateIds, true))
                                                <flux:badge size="sm" color="zinc">{{ __('Double tap') }}</flux:badge>
                                                <flux:button size="xs" variant="ghost" wire:click="ignorePunch({{ $punch->id }})">{{ __('Ignore') }}</flux:button>
                                            @else
                                                <flux:button size="xs" variant="ghost" wire:click="ignorePunch({{ $punch->id }})">{{ __('Ignore') }}</flux:button>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <flux:text size="sm">{{ __('No punches in this window.') }}</flux:text>
                                @endforelse

                                @if ($row['confirmed']->isNotEmpty())
                                    <flux:separator class="my-2" />
                                    <flux:heading size="sm">{{ __('Confirmed sessions') }}</flux:heading>
                                    @foreach ($row['confirmed'] as $session)
                                        <div wire:key="confirmed-{{ $session->id }}" class="flex items-center justify-between gap-2 text-sm">
                                            <span>{{ $session->starts_at->format('H:i') }} → {{ $session->ends_at->format('D H:i') }} · {{ $session->duties->map->label()->join(', ') }}</span>
                                            <flux:button size="xs" variant="ghost" wire:click="unconfirmSession({{ $session->id }})" wire:confirm="{{ __('Send this session back for pairing?') }}">{{ __('Undo') }}</flux:button>
                                        </div>
                                    @endforeach
                                @endif
                            </div>

                            <div class="flex flex-col gap-4">
                                @foreach ($drafts as $index => $draft)
                                    @php $preview = $this->draftDuties($index); @endphp
                                    <div wire:key="draft-{{ $employee->id }}-{{ $index }}" class="flex flex-col gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                                        <div class="flex items-center justify-between gap-2">
                                            <flux:heading size="sm">{{ __('Session :number', ['number' => $index + 1]) }}
                                                @if ($draft['break_minutes'] > 0)
                                                    <span class="font-normal text-zinc-500">· {{ __(':time out in between', ['time' => $this->minutesLabel($draft['break_minutes'])]) }}</span>
                                                @endif
                                            </flux:heading>
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeDraft({{ $index }})" :aria-label="__('Remove session')" />
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <flux:field>
                                                <flux:label>{{ __('Arrived') }}</flux:label>
                                                <flux:input type="datetime-local" wire:model.live.blur="drafts.{{ $index }}.starts_at" />
                                                @if ($draft['in_punch_id'] && $draft['starts_at'] !== $draft['in_time'])
                                                    <flux:description class="text-amber-700">{{ __('Changed from the punch time') }}</flux:description>
                                                @endif
                                            </flux:field>
                                            <flux:field>
                                                <flux:label>{{ __('Left') }}</flux:label>
                                                <flux:input type="datetime-local" wire:model.live.blur="drafts.{{ $index }}.ends_at" />
                                                @if (! $draft['out_punch_id'])
                                                    <flux:description class="text-amber-700">{{ __('No out punch — type the leaving time') }}</flux:description>
                                                @endif
                                                <flux:error name="drafts.{{ $index }}.ends_at" />
                                            </flux:field>
                                        </div>

                                        <div class="flex flex-col gap-2">
                                            @foreach ($draft['duty_template_ids'] as $dutyIndex => $templateId)
                                                <div wire:key="draft-{{ $index }}-duty-{{ $dutyIndex }}" class="flex flex-wrap items-center gap-2">
                                                    <flux:badge size="sm" color="violet">{{ __('Duty :number', ['number' => $dutyIndex + 1]) }}</flux:badge>
                                                    <flux:select size="sm" class="w-56" wire:model.live="drafts.{{ $index }}.duty_template_ids.{{ $dutyIndex }}">
                                                        <flux:select.option value="">{{ __('Custom (actual times)') }}</flux:select.option>
                                                        @foreach ($this->templates as $template)
                                                            <flux:select.option value="{{ $template->id }}">{{ $template->label() }}</flux:select.option>
                                                        @endforeach
                                                    </flux:select>
                                                    @if (isset($preview[$dutyIndex]))
                                                        <flux:text size="sm">
                                                            {{ $preview[$dutyIndex]['scheduled_start']->format('D H:i') }}–{{ $preview[$dutyIndex]['scheduled_end']->format('H:i') }}
                                                            @if ($preview[$dutyIndex]['late_minutes'] > 0)
                                                                · {{ __('late :time', ['time' => $this->minutesLabel($preview[$dutyIndex]['late_minutes'])]) }}
                                                            @endif
                                                            @if ($preview[$dutyIndex]['early_leave_minutes'] > 0)
                                                                · {{ __('left :time early', ['time' => $this->minutesLabel($preview[$dutyIndex]['early_leave_minutes'])]) }}
                                                            @endif
                                                        </flux:text>
                                                    @endif
                                                    @if (count($draft['duty_template_ids']) > 1)
                                                        <flux:button size="xs" variant="ghost" icon="minus" wire:click="removeDuty({{ $index }}, {{ $dutyIndex }})" :aria-label="__('Remove duty')" />
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>

                                        <flux:field>
                                            <flux:label>{{ __('Note') }}</flux:label>
                                            <flux:input wire:model="drafts.{{ $index }}.notes" :placeholder="__('e.g. Night staff absent, stayed on emergency')" />
                                            <flux:error name="drafts.{{ $index }}.notes" />
                                        </flux:field>

                                        <div class="flex flex-wrap gap-2">
                                            <flux:button size="sm" icon="plus" wire:click="addDuty({{ $index }})">{{ __('Add duty') }}</flux:button>
                                            <flux:button size="sm" icon="sparkles" wire:click="suggestDuties({{ $index }})">{{ __('Re-suggest shifts') }}</flux:button>
                                            <flux:button size="sm" variant="primary" icon="check" wire:click="saveDraft({{ $index }})">
                                                {{ trans_choice('{1} Confirm 1 duty|[2,*] Confirm :count duties', max(1, count($draft['duty_template_ids']))) }}
                                            </flux:button>
                                        </div>
                                    </div>
                                @endforeach

                                <div>
                                    <flux:button size="sm" icon="plus" variant="ghost" wire:click="addDraft">{{ __('Add session by hand') }}</flux:button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-4 py-10 text-center text-zinc-500">
                    {{ __('Nobody here. Link machine users to staff profiles on the Machine page.') }}
                </div>
            @endforelse
        </div>
    </flux:card>
</div>
