<?php

use App\Enums\LeaveKind;
use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\StaffLeave;
use App\Services\StaffLeaveService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Leaves & Absences')] class extends Component
{
    #[Url]
    public string $month = '';

    #[Url]
    public string $kindFilter = 'all';

    public string $employeeId = '';

    public string $from = '';

    public string $to = '';

    public string $kind = 'informed';

    public bool $halfDay = false;

    public string $reason = '';

    public bool $forcePaid = false;

    public function mount(): void
    {
        if (! rescue(fn () => CarbonImmutable::createFromFormat('Y-m', $this->month), null, false)) {
            $this->month = today()->format('Y-m');
        }

        $this->from = today()->toDateString();
        $this->to = today()->toDateString();
    }

    #[Computed]
    public function start(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    #[Computed]
    public function staff()
    {
        return Employee::query()->active()->tracksAttendance()->orderBy('name')->get(['id', 'name', 'staff_category']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, StaffLeave>
     */
    #[Computed]
    public function leaves()
    {
        return StaffLeave::query()
            ->with(['employee:id,name,staff_category', 'creator:id,name'])
            ->whereDate('leave_date', '>=', $this->start)
            ->whereDate('leave_date', '<=', $this->start->endOfMonth())
            ->when($this->kindFilter === 'uninformed', fn ($query) => $query->where('kind', LeaveKind::Uninformed))
            ->when($this->kindFilter === 'unpaid', fn ($query) => $query->whereColumn('paid_days', '<', 'days'))
            ->orderByDesc('leave_date')
            ->orderBy('employee_id')
            ->get();
    }

    /**
     * Paid allowance left for the person picked in the form, in the month of the first date.
     */
    #[Computed]
    public function allowanceLeft(): ?float
    {
        $employee = $this->employeeId !== '' ? Employee::query()->find((int) $this->employeeId) : null;
        $date = rescue(fn () => CarbonImmutable::parse($this->from), null, false);

        return $employee && $date ? app(StaffLeaveService::class)->allowanceLeft($employee, $date) : null;
    }

    public function previousMonth(): void
    {
        $this->month = $this->start->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->start->addMonth()->format('Y-m');
    }

    /**
     * Most leave is a single day, so picking the first day resets the last day to match.
     */
    public function updatedFrom(string $value): void
    {
        $this->to = $value;
    }

    public function save(StaffLeaveService $leaves): void
    {
        $validated = $this->validate([
            'employeeId' => ['required', Rule::exists('employees', 'id')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'kind' => ['required', Rule::enum(LeaveKind::class)],
            'halfDay' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'forcePaid' => ['boolean'],
        ], [], ['employeeId' => __('staff')]);

        try {
            $created = $leaves->record(
                Employee::query()->findOrFail((int) $validated['employeeId']),
                CarbonImmutable::parse($validated['from']),
                CarbonImmutable::parse($validated['to']),
                LeaveKind::from($validated['kind']),
                $validated['halfDay'],
                $validated['reason'] ?: null,
                $validated['forcePaid'] && auth()->user()->isAdmin(),
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            $this->addError('from', collect($exception->errors())->flatten()->first());

            return;
        }

        $unpaid = $created->sum(fn (StaffLeave $leave): float => $leave->unpaidDays());

        $this->reset('reason', 'halfDay', 'forcePaid');
        unset($this->leaves, $this->allowanceLeft);

        Flux::toast(
            variant: $unpaid > 0 ? 'warning' : 'success',
            text: $unpaid > 0
                ? __('Saved. :days day(s) will be deducted from pay.', ['days' => $unpaid])
                : __('Leave saved.'),
        );
    }

    public function delete(int $leaveId, StaffLeaveService $leaves): void
    {
        $leaves->delete(StaffLeave::query()->findOrFail($leaveId));
        unset($this->leaves, $this->allowanceLeft);

        Flux::toast(text: __('Leave removed.'));
    }

    public function daysLabel(float $days): string
    {
        return $days == 0.5 ? __('½ day') : trans_choice('{1} 1 day|[2,*] :count days', (int) $days);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-hr-nav current="hr.leaves" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Leaves & absences') }}</flux:heading>
            <flux:text class="mt-1">
                {{ trans_choice('{0} Informed leaves are not paid.|{1} 1 informed leave day per month is paid.|[2,*] :count informed leave days per month are paid.', \App\Models\AppSetting::attendanceRule(\App\Models\AppSetting::PaidLeavesPerMonth)) }}
                {{ __('Beyond that, and for any uninformed absence, the day is deducted.') }}
            </flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:button icon="chevron-left" wire:click="previousMonth" :aria-label="__('Previous month')" />
            <flux:heading class="w-36 text-center">{{ $this->start->format('F Y') }}</flux:heading>
            <flux:button icon="chevron-right" wire:click="nextMonth" :aria-label="__('Next month')" />
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[380px_minmax(0,1fr)]">
        <flux:card>
            <flux:heading size="lg">{{ __('Record leave') }}</flux:heading>

            <form wire:submit="save" class="mt-4 space-y-4">
                <flux:field>
                    <flux:label>{{ __('Staff') }}</flux:label>
                    <flux:select wire:model.live="employeeId" :placeholder="__('Choose…')">
                        @foreach ($this->staff as $person)
                            <flux:select.option value="{{ $person->id }}">{{ $person->name }} — {{ $person->staff_category->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="employeeId" />
                </flux:field>

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('From') }}</flux:label>
                        <flux:input type="date" wire:model.live="from" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('To') }}</flux:label>
                        <flux:input type="date" wire:model="to" :disabled="$halfDay" />
                    </flux:field>
                </div>
                <flux:error name="from" />
                <flux:error name="to" />

                <flux:radio.group wire:model.live="kind" :label="__('Was it informed in advance?')">
                    @foreach (LeaveKind::cases() as $leaveKind)
                        <flux:radio value="{{ $leaveKind->value }}" :label="$leaveKind->label()" />
                    @endforeach
                </flux:radio.group>

                <flux:checkbox wire:model.live="halfDay" :label="__('Half day')" />

                <flux:field>
                    <flux:label>{{ __('Reason') }}</flux:label>
                    <flux:input wire:model="reason" :placeholder="__('Optional')" />
                </flux:field>

                @if ($this->allowanceLeft !== null && $kind === 'informed')
                    <flux:callout :color="$this->allowanceLeft > 0 ? 'sky' : 'amber'" icon="information-circle">
                        <flux:callout.text>
                            {{ __(':days paid leave left in :month.', ['days' => $this->allowanceLeft == 0.5 ? '½ day' : rtrim(rtrim(number_format($this->allowanceLeft, 1), '0'), '.'), 'month' => \Carbon\CarbonImmutable::parse($from)->format('F')]) }}
                            @if ($this->allowanceLeft <= 0)
                                {{ __('This leave will be deducted.') }}
                            @endif
                        </flux:callout.text>
                    </flux:callout>
                    @if (auth()->user()->isAdmin())
                        <flux:checkbox wire:model="forcePaid" :label="__('Pay it anyway (admin override)')" />
                    @endif
                @endif

                <flux:button type="submit" variant="primary" class="w-full">{{ __('Save leave') }}</flux:button>
            </form>
        </flux:card>

        <flux:card class="min-w-0">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ $this->start->format('F Y') }}</flux:heading>
                <flux:select size="sm" wire:model.live="kindFilter" class="w-48">
                    <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                    <flux:select.option value="uninformed">{{ __('Uninformed only') }}</flux:select.option>
                    <flux:select.option value="unpaid">{{ __('Deducted only') }}</flux:select.option>
                </flux:select>
            </div>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Staff') }}</flux:table.column>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Kind') }}</flux:table.column>
                    <flux:table.column>{{ __('Pay') }}</flux:table.column>
                    <flux:table.column>{{ __('Reason') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->leaves as $leave)
                        <flux:table.row wire:key="leave-{{ $leave->id }}">
                            <flux:table.cell class="font-medium">{{ $leave->employee->name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $leave->leave_date->format('D j M') }}
                                @if ($leave->isHalfDay())
                                    <flux:badge size="sm">{{ __('½ day') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$leave->kind->color()">{{ $leave->kind->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                @if ($leave->unpaidDays() > 0)
                                    <flux:badge size="sm" color="amber">{{ __('Deducted :days', ['days' => $this->daysLabel($leave->unpaidDays())]) }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">{{ $leave->force_paid ? __('Paid (override)') : __('Paid') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                {{ $leave->reason ?? '—' }}
                                @if ($leave->creator)
                                    <flux:text size="sm">{{ __('by :name', ['name' => $leave->creator->name]) }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $leave->id }})" wire:confirm="{{ __('Remove this leave?') }}" :aria-label="__('Remove leave')" />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No leaves recorded this month.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
</div>
