@php
    use App\Services\WardMaintenanceChecklistDefinition;

    /** @var \App\Models\WardMaintenanceEntry $entry */
    $definition = app(WardMaintenanceChecklistDefinition::class);
    $labels = array_merge(
        $definition->sectionAItems(),
        $definition->sectionBItems(),
        $definition->sectionCGyneItems(),
        $definition->sectionCPrivateItems(),
        $definition->sectionDGyneItems(),
        $definition->sectionDPrivateItems(),
        $definition->sectionEItems(),
        $definition->sectionFItems(),
        $definition->sectionGItems(),
    );
    $faultAnswers = $entry->answers->filter(fn ($answer) => $answer->isFault());
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
            <div><span class="text-zinc-500">{{ __('Checked By') }}:</span> {{ $entry->checked_by_name }} @if($entry->checked_by_time) ({{ $entry->checked_by_time }}) @endif</div>
            <div><span class="text-zinc-500">{{ __('Supervisor') }}:</span> {{ $entry->supervisor_name ?: '—' }} @if($entry->supervisor_time) ({{ $entry->supervisor_time }}) @endif</div>
            <div><span class="text-zinc-500">{{ __('Patient safety fault') }}:</span> {{ strtoupper($entry->patient_safety_fault ?? '—') }}</div>
            <div><span class="text-zinc-500">{{ __('Reported to maintenance') }}:</span> {{ strtoupper($entry->patient_safety_reported ?? '—') }}</div>
            <div><span class="text-zinc-500">{{ __('Room/bed unavailable') }}:</span> {{ strtoupper($entry->room_unavailable ?? '—') }}</div>
            <div><span class="text-zinc-500">{{ __('Beds out of service') }}:</span> {{ $entry->beds_out_of_service ?: '—' }}</div>
            <div class="sm:col-span-2"><span class="text-zinc-500">{{ __('Reason / Remarks') }}:</span> {{ $entry->reason_remarks ?: '—' }}</div>
            <div class="sm:col-span-2"><span class="text-zinc-500">{{ __('Supervisor Remarks') }}:</span> {{ $entry->supervisor_remarks ?: '—' }}</div>
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
                            <th class="py-2 pe-3 text-start">{{ __('Location') }}</th>
                            <th class="py-2 text-start">{{ __('Detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($faultAnswers as $answer)
                            <tr class="border-b border-zinc-100 bg-red-50 dark:border-zinc-800 dark:bg-red-950/30" wire:key="fault-answer-{{ $answer->id }}">
                                <td class="py-2 pe-3">{{ $answer->section }}</td>
                                <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                                <td class="py-2 pe-3">{{ $answer->location_key !== '' ? $answer->location_key : '—' }}</td>
                                <td class="py-2">
                                    @if ($answer->section === 'E')
                                        {{ $answer->available === false ? __('Not available') : '' }}
                                        {{ $answer->functional === false ? __('Not functional') : '' }}
                                        {{ $answer->remarks ? '— '.$answer->remarks : '' }}
                                    @else
                                        {{ $answer->status?->label() ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    @if ($entry->faults->isNotEmpty())
        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Fault Report Log') }}</flux:heading>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="py-2 pe-3 text-start">{{ __('Time') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Bed / Room') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Description') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Priority') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Reported To') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('Action') }}</th>
                            <th class="py-2 text-start">{{ __('Resolved') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entry->faults as $fault)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="fault-row-{{ $fault->id }}">
                                <td class="py-2 pe-3">{{ $fault->fault_time ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $fault->bed_room ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $fault->description ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $fault->priority?->label() ?? '—' }}</td>
                                <td class="py-2 pe-3">{{ $fault->reported_to ?: '—' }}</td>
                                <td class="py-2 pe-3">{{ $fault->action_taken ?: '—' }}</td>
                                <td class="py-2">
                                    @if ($fault->resolved === null)
                                        —
                                    @elseif ($fault->resolved)
                                        {{ __('Yes') }}
                                    @else
                                        {{ __('No') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    <flux:card>
        <flux:heading level="2" class="mb-4">{{ __('All Checklist Answers') }}</flux:heading>
        <div class="overflow-x-auto max-h-[28rem]">
            <table class="w-full border-collapse text-sm">
                <thead class="sticky top-0 bg-white dark:bg-zinc-800">
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="py-2 pe-3 text-start">{{ __('Section') }}</th>
                        <th class="py-2 pe-3 text-start">{{ __('Item') }}</th>
                        <th class="py-2 pe-3 text-start">{{ __('Location') }}</th>
                        <th class="py-2 text-start">{{ __('Result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entry->answers as $answer)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $answer->isFault() ? 'bg-red-50 dark:bg-red-950/30' : '' }}" wire:key="answer-{{ $answer->id }}">
                            <td class="py-2 pe-3">{{ $answer->section }}</td>
                            <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                            <td class="py-2 pe-3">{{ $answer->location_key !== '' ? $answer->location_key : '—' }}</td>
                            <td class="py-2">
                                @if ($answer->section === 'E')
                                    {{ __('Available') }}: {{ $answer->available ? __('Yes') : __('No') }},
                                    {{ __('Functional') }}: {{ $answer->functional ? __('Yes') : __('No') }}
                                    @if ($answer->remarks)
                                        — {{ $answer->remarks }}
                                    @endif
                                @else
                                    {{ $answer->status?->label() ?? '—' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
