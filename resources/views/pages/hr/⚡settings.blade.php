<?php

use App\Models\AppSetting;
use App\Models\Holiday;
use App\Models\ShiftTemplate;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shifts & Attendance Rules')] class extends Component
{
    /** Badge colours a shift can use. */
    public const Colors = ['sky', 'indigo', 'violet', 'emerald', 'amber', 'rose', 'teal', 'zinc'];

    public bool $showShiftModal = false;

    public ?int $editingShiftId = null;

    public string $shiftName = '';

    public string $shiftStartsAt = '08:00';

    public string $shiftEndsAt = '15:00';

    public string $shiftColor = 'sky';

    public bool $shiftIsActive = true;

    /** @var array<string, string> */
    public array $rules = [];

    public string $holidayDate = '';

    public string $holidayName = '';

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }

        foreach (array_keys(AppSetting::AttendanceRules) as $key) {
            $this->rules[$this->ruleField($key)] = (string) AppSetting::attendanceRule($key);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ShiftTemplate>
     */
    #[Computed]
    public function shifts()
    {
        return ShiftTemplate::query()->withCount('duties')->orderByDesc('is_active')->orderBy('sort_order')->orderBy('starts_at')->get();
    }

    public function openShiftModal(?int $shiftId = null): void
    {
        $shift = $shiftId ? ShiftTemplate::query()->findOrFail($shiftId) : null;

        $this->editingShiftId = $shift?->id;
        $this->shiftName = $shift?->name ?? '';
        $this->shiftStartsAt = $shift ? substr($shift->starts_at, 0, 5) : '08:00';
        $this->shiftEndsAt = $shift ? substr($shift->ends_at, 0, 5) : '15:00';
        $this->shiftColor = $shift?->color ?? 'sky';
        $this->shiftIsActive = $shift?->is_active ?? true;
        $this->resetValidation();
        $this->showShiftModal = true;
    }

    public function saveShift(): void
    {
        $validated = $this->validate([
            'shiftName' => ['required', 'string', 'max:50', Rule::unique('shift_templates', 'name')->ignore($this->editingShiftId)],
            'shiftStartsAt' => ['required', 'date_format:H:i'],
            'shiftEndsAt' => ['required', 'date_format:H:i', 'different:shiftStartsAt'],
            'shiftColor' => ['required', Rule::in(self::Colors)],
            'shiftIsActive' => ['boolean'],
        ]);

        $attributes = [
            'name' => $validated['shiftName'],
            'starts_at' => $validated['shiftStartsAt'].':00',
            'ends_at' => $validated['shiftEndsAt'].':00',
            'color' => $validated['shiftColor'],
            'is_active' => $validated['shiftIsActive'],
        ];

        if ($this->editingShiftId) {
            ShiftTemplate::query()->findOrFail($this->editingShiftId)->update($attributes);
        } else {
            ShiftTemplate::query()->create($attributes + ['sort_order' => (int) ShiftTemplate::query()->max('sort_order') + 1]);
        }

        $this->showShiftModal = false;
        unset($this->shifts);

        Flux::toast(variant: 'success', text: __('Shift saved.'));
    }

    public function toggleShift(int $shiftId): void
    {
        $shift = ShiftTemplate::query()->findOrFail($shiftId);
        $shift->update(['is_active' => ! $shift->is_active]);
        unset($this->shifts);
    }

    public function saveRules(): void
    {
        $validationRules = [];

        foreach (AppSetting::AttendanceRules as $key => $rule) {
            $validationRules['rules.'.$this->ruleField($key)] = ['required', 'integer', 'between:'.$rule['min'].','.$rule['max']];
        }

        $this->validate($validationRules);

        foreach (array_keys(AppSetting::AttendanceRules) as $key) {
            AppSetting::set($key, (string) (int) $this->rules[$this->ruleField($key)]);
        }

        Flux::toast(variant: 'success', text: __('Rules saved.'));
    }

    /**
     * Holidays from the start of last year onwards, soonest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Holiday>
     */
    #[Computed]
    public function holidays()
    {
        return Holiday::query()->whereDate('holiday_date', '>=', today()->subYear()->startOfYear())->orderBy('holiday_date')->get();
    }

    public function addHoliday(): void
    {
        $this->validate([
            'holidayDate' => ['required', 'date', function (string $attribute, mixed $value, Closure $fail): void {
                if (Holiday::query()->whereDate('holiday_date', $value)->exists()) {
                    $fail(__('There is already a holiday on that date.'));
                }
            }],
            'holidayName' => ['required', 'string', 'max:100'],
        ]);

        Holiday::query()->create(['holiday_date' => $this->holidayDate, 'name' => $this->holidayName]);

        $this->reset('holidayDate', 'holidayName');
        unset($this->holidays);

        Flux::toast(variant: 'success', text: __('Holiday added.'));
    }

    public function deleteHoliday(int $holidayId): void
    {
        Holiday::query()->findOrFail($holidayId)->delete();
        unset($this->holidays);
    }

    public function ruleField(string $key): string
    {
        return str_replace('attendance.', '', $key);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-hr-nav current="hr.settings" />

    <flux:heading size="xl" level="1">{{ __('Shifts & attendance rules') }}</flux:heading>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <flux:card>
            <div class="flex items-center justify-between gap-4">
                <flux:heading size="lg">{{ __('Shift templates') }}</flux:heading>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="openShiftModal">{{ __('Add shift') }}</flux:button>
            </div>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Starts') }}</flux:table.column>
                    <flux:table.column>{{ __('Ends') }}</flux:table.column>
                    <flux:table.column>{{ __('Counts as') }}</flux:table.column>
                    <flux:table.column>{{ __('Used') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->shifts as $shift)
                        <flux:table.row wire:key="shift-{{ $shift->id }}" @class(['opacity-50' => ! $shift->is_active])>
                            <flux:table.cell><flux:badge size="sm" :color="$shift->color">{{ $shift->name }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="font-mono">{{ substr($shift->starts_at, 0, 5) }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ substr($shift->ends_at, 0, 5) }}{{ $shift->crossesMidnight() ? ' (+1)' : '' }}</flux:table.cell>
                            <flux:table.cell>{{ __('1 duty') }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($shift->duties_count) }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-1">
                                    <flux:button size="xs" variant="ghost" wire:click="openShiftModal({{ $shift->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="toggleShift({{ $shift->id }})">{{ $shift->is_active ? __('Turn off') : __('Turn on') }}</flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <flux:text size="sm" class="mt-4">{{ __('Pairing suggests the closest matching shift for each duty. Anyone can also be paired with custom times; shifts are only used to measure late arrival and early leaving.') }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Rules') }}</flux:heading>

            <form wire:submit="saveRules" class="mt-4 space-y-4">
                @foreach (\App\Models\AppSetting::AttendanceRules as $key => $rule)
                    <flux:field wire:key="rule-{{ $key }}">
                        <flux:label>{{ __($rule['label']) }}</flux:label>
                        <flux:input type="number" min="{{ $rule['min'] }}" max="{{ $rule['max'] }}" wire:model="rules.{{ $this->ruleField($key) }}" />
                        <flux:error name="rules.{{ $this->ruleField($key) }}" />
                    </flux:field>
                @endforeach

                <flux:button type="submit" variant="primary">{{ __('Save rules') }}</flux:button>
            </form>
        </flux:card>
    </div>

    <flux:card>
        <flux:heading size="lg">{{ __('Public holidays') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Leave is not counted on a holiday. Whoever works on a holiday gets a normal duty.') }}</flux:text>

        <form wire:submit="addHoliday" class="mt-4 flex flex-wrap items-start gap-3">
            <flux:field>
                <flux:input type="date" wire:model="holidayDate" :aria-label="__('Holiday date')" />
                <flux:error name="holidayDate" />
            </flux:field>
            <flux:field class="min-w-56 flex-1">
                <flux:input wire:model="holidayName" :placeholder="__('e.g. Eid ul Fitr')" :aria-label="__('Holiday name')" />
                <flux:error name="holidayName" />
            </flux:field>
            <flux:button type="submit" variant="primary" icon="plus">{{ __('Add holiday') }}</flux:button>
        </form>

        <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @forelse ($this->holidays as $holiday)
                <div wire:key="holiday-{{ $holiday->id }}" @class(['flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700', 'opacity-60' => $holiday->holiday_date->isPast() && ! $holiday->holiday_date->isToday()])>
                    <div>
                        <div class="font-medium">{{ $holiday->name }}</div>
                        <flux:text size="sm">{{ $holiday->holiday_date->format('D j M Y') }}</flux:text>
                    </div>
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteHoliday({{ $holiday->id }})" wire:confirm="{{ __('Remove this holiday?') }}" :aria-label="__('Remove holiday')" />
                </div>
            @empty
                <flux:text>{{ __('No holidays added yet.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>

    <flux:modal wire:model="showShiftModal" class="w-full max-w-md">
        <flux:heading size="lg">{{ $editingShiftId ? __('Edit shift') : __('Add shift') }}</flux:heading>

        <form wire:submit="saveShift" class="mt-6 space-y-4">
            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="shiftName" :placeholder="__('e.g. Day 12h')" />
                <flux:error name="shiftName" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Starts') }}</flux:label>
                    <flux:input type="time" wire:model="shiftStartsAt" />
                    <flux:error name="shiftStartsAt" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Ends') }}</flux:label>
                    <flux:input type="time" wire:model="shiftEndsAt" />
                    <flux:description>{{ __('Earlier than the start = next day.') }}</flux:description>
                    <flux:error name="shiftEndsAt" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Colour') }}</flux:label>
                <flux:select wire:model="shiftColor">
                    @foreach ($this::Colors as $color)
                        <flux:select.option value="{{ $color }}">{{ ucfirst($color) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:switch wire:model="shiftIsActive" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showShiftModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
