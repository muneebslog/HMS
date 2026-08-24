@php
    use App\Services\EquipmentInspectionChecklistDefinition;

    /** @var \App\Models\EquipmentInspectionEntry $entry */
    $definition = app(EquipmentInspectionChecklistDefinition::class);
    $labels = $definition->allItemLabels($entry->area);
    $signOffFields = $definition->signOffFields($entry->area);
    $faultAnswers = $entry->answers->filter(fn ($answer) => $answer->isFault());
    $equipmentAnswers = $entry->answers->filter(fn ($answer) => $answer->isEquipmentRow());
    $checklistAnswers = $entry->answers->filter(fn ($answer) => ! $answer->isEquipmentRow());
@endphp

<div class="flex flex-col gap-6">
    <flux:card>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading level="2">{{ __('Submitted Checklist') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Submitted by :name at :time', [
                        'name' => $entry->user->name,
                        'time' => $entry->submitted_at->format('Y-m-d H:i'),
                    ]) }}
                </flux:text>
            </div>
            @if ($entry->hasFaults())
                <flux:badge color="red">{{ __('Faults reported') }}</flux:badge>
            @else
                <flux:badge color="green">{{ __('No faults') }}</flux:badge>
            @endif
        </div>

        <div class="grid gap-3 text-sm sm:grid-cols-2">
            <div><span class="text-zinc-500">{{ __('Area') }}:</span> {{ $entry->area->label() }}</div>
            <div><span class="text-zinc-500">{{ __('Shift') }}:</span> {{ $entry->shift->label() }}</div>
            <div><span class="text-zinc-500">{{ __('Checked By') }}:</span> {{ $entry->checked_by_name }}</div>
            <div><span class="text-zinc-500">{{ __('Supervisor') }}:</span> {{ $entry->supervisor_name ?: '—' }}</div>
            @foreach ($signOffFields as $fieldKey => $field)
                <div>
                    <span class="text-zinc-500">{{ $field['label'] }}:</span>
                    {{ strtoupper((string) ($entry->sign_off[$fieldKey] ?? '—')) }}
                </div>
            @endforeach
        </div>
    </flux:card>

    @if ($faultAnswers->isNotEmpty())
        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Fault Answers') }}</flux:heading>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="py-2 pe-3 text-start">{{ __('Section') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Item') }}</th>
                            <th class="py-2 text-start">{{ __('Detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($faultAnswers as $answer)
                            <tr class="border-b border-zinc-100 bg-red-50 dark:border-zinc-800 dark:bg-red-950/30" wire:key="fault-answer-{{ $answer->id }}">
                                <td class="py-2 pe-3">{{ $answer->section }}</td>
                                <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                                <td class="py-2">
                                    @if ($answer->isEquipmentRow())
                                        @if ($answer->present === false) {{ __('Not present') }} @endif
                                        @if ($answer->functional === false) {{ __('Not functional') }} @endif
                                        @if ($answer->maint_req === true) {{ __('Maintenance required') }} @endif
                                        @if ($answer->remarks) — {{ $answer->remarks }} @endif
                                    @else
                                        {{ __('Not done') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    @if ($equipmentAnswers->isNotEmpty())
        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Equipment Answers') }}</flux:heading>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="py-2 pe-3 text-start">{{ __('Item') }}</th>
                            <th class="px-2 py-2 text-center">{{ __('Present') }}</th>
                            <th class="px-2 py-2 text-center">{{ __('Functional') }}</th>
                            <th class="px-2 py-2 text-center">{{ __('Clean') }}</th>
                            <th class="px-2 py-2 text-center">{{ __('Maint. Req.') }}</th>
                            <th class="py-2 text-start">{{ __('Remarks') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($equipmentAnswers as $answer)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="equip-answer-{{ $answer->id }}">
                                <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                                <td class="px-2 py-2 text-center">{{ $answer->present ? __('Yes') : __('No') }}</td>
                                <td class="px-2 py-2 text-center">{{ $answer->functional ? __('Yes') : __('No') }}</td>
                                <td class="px-2 py-2 text-center">{{ $answer->clean ? __('Yes') : __('No') }}</td>
                                <td class="px-2 py-2 text-center">{{ $answer->maint_req ? __('Yes') : __('No') }}</td>
                                <td class="py-2">{{ $answer->remarks ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    @if ($checklistAnswers->isNotEmpty())
        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Checklist Answers') }}</flux:heading>
            <div class="space-y-2 text-sm">
                @foreach ($checklistAnswers as $answer)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800" wire:key="check-answer-{{ $answer->id }}">
                        <span>{{ $labels[$answer->item_key] ?? $answer->item_key }}</span>
                        @if ($answer->checked)
                            <flux:badge size="sm" color="green">{{ __('Done') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="red">{{ __('Not done') }}</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    @if ($entry->registerRows->isNotEmpty())
        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Maintenance Register') }}</flux:heading>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="py-2 pe-3 text-start">{{ __('Date') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Department') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Equipment') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Problem') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Action') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Technician') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Completed') }}</th>
                            <th class="py-2 text-start">{{ __('Sign') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entry->registerRows as $row)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="register-row-{{ $row->id }}">
                                <td class="py-2 pe-3">{{ $row->item_date?->format('Y-m-d') ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $row->department ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $row->equipment ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $row->problem ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $row->action_taken ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $row->technician ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $row->completed_date?->format('Y-m-d') ?: '—' }}</td>
                                <td class="py-2">{{ $row->signed ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif
</div>
