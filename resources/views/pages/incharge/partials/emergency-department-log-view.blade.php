@php
    use App\Services\EmergencyDepartmentLogDefinition;

    /** @var \App\Models\EmergencyDepartmentLogEntry $entry */
    $definition = app(EmergencyDepartmentLogDefinition::class);
    $labels = $definition->allItemLabels();
    $faultAnswers = $entry->answers->filter(fn ($answer) => $answer->isFault());
    $handoverAnswers = $entry->answers->where('section', 'A');
    $equipmentAnswers = $entry->answers->where('section', 'B');
    $crashCartAnswers = $entry->answers->filter(fn ($answer) => str_starts_with($answer->section, 'C'));
    $cleaningAnswers = $entry->answers->filter(fn ($answer) => str_starts_with($answer->section, 'D'));
@endphp

<div class="flex flex-col gap-6">
    <flux:card>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading level="2">{{ __('Submitted Log') }}</flux:heading>
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
            <div><span class="text-zinc-500">{{ __('Shift') }}:</span> {{ $entry->shift->label() }}</div>
            <div><span class="text-zinc-500">{{ __('Date') }}:</span> {{ $entry->checklist_date->format('M j, Y') }}</div>
            <div><span class="text-zinc-500">{{ __('Completed By') }}:</span> {{ $entry->completed_by_name }}</div>
            <div><span class="text-zinc-500">{{ __('Completed At') }}:</span> {{ $entry->submitted_at->format('Y-m-d H:i') }}</div>
            <div><span class="text-zinc-500">{{ __('Supervisor') }}:</span> {{ $entry->supervisor_name ?: '—' }}</div>
            <div class="sm:col-span-2">
                <span class="text-zinc-500">{{ __('Equipment Issues / Maintenance Log') }}:</span>
                {{ $entry->equipment_issues_log ?: '—' }}
            </div>
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
                                    @if ($answer->status?->value === 'issue')
                                        {{ __('Issue') }}
                                    @endif
                                    @if ($answer->adequate === false)
                                        {{ __('Not adequate') }}
                                    @endif
                                    @if ($answer->checked === false)
                                        {{ __('Not done') }}
                                    @endif
                                    @if ($answer->remarks)
                                        — {{ $answer->remarks }}
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
        <flux:heading level="2" class="mb-4">{{ __('A. Department Summary & Handover') }}</flux:heading>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="py-2 pe-3 text-start">{{ __('Metric') }}</th>
                        <th class="py-2 pe-3 text-start">{{ __('Count') }}</th>
                        <th class="py-2 text-start">{{ __('Notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($handoverAnswers as $answer)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="handover-{{ $answer->id }}">
                            <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                            <td class="py-2 pe-3">{{ $answer->count ?? '—' }}</td>
                            <td class="py-2">{{ $answer->remarks ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </flux:card>

    <flux:card>
        <flux:heading level="2" class="mb-4">{{ __('B. Emergency Equipment Status') }}</flux:heading>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="py-2 pe-3 text-start">{{ __('Equipment') }}</th>
                        <th class="py-2 pe-3 text-start">{{ __('Status') }}</th>
                        <th class="py-2 text-start">{{ __('Issue / Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($equipmentAnswers as $answer)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $answer->isFault() ? 'bg-red-50 dark:bg-red-950/30' : '' }}" wire:key="equip-{{ $answer->id }}">
                            <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                            <td class="py-2 pe-3">{{ $answer->status?->label() ?? '—' }}</td>
                            <td class="py-2">{{ $answer->remarks ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </flux:card>

    <flux:card>
        <flux:heading level="2" class="mb-4">{{ __('C. Crash Cart / ER Trolley Stock') }}</flux:heading>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="py-2 pe-3 text-start">{{ __('Drawer') }}</th>
                        <th class="py-2 pe-3 text-start">{{ __('Item') }}</th>
                        <th class="py-2 pe-3 text-start">{{ __('Stock') }}</th>
                        <th class="py-2 text-start">{{ __('Notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($crashCartAnswers as $answer)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $answer->isFault() ? 'bg-red-50 dark:bg-red-950/30' : '' }}" wire:key="stock-{{ $answer->id }}">
                            <td class="py-2 pe-3">{{ $definition->crashCartDrawers()[$answer->section]['label'] ?? $answer->section }}</td>
                            <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                            <td class="py-2 pe-3">
                                @if ($answer->adequate === null)
                                    —
                                @elseif ($answer->adequate)
                                    {{ __('Adequate') }}
                                @else
                                    {{ __('Short / Missing') }}
                                @endif
                            </td>
                            <td class="py-2">{{ $answer->remarks ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </flux:card>

    <flux:card>
        <flux:heading level="2" class="mb-4">{{ __('D. Cleaning & Facility Maintenance') }}</flux:heading>
        <div class="overflow-x-auto max-h-[28rem]">
            <table class="w-full border-collapse text-sm">
                <thead class="sticky top-0 bg-white dark:bg-zinc-800">
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="py-2 pe-3 text-start">{{ __('Group') }}</th>
                        <th class="py-2 pe-3 text-start">{{ __('Item') }}</th>
                        <th class="py-2 text-start">{{ __('Result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cleaningAnswers as $answer)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $answer->isFault() ? 'bg-red-50 dark:bg-red-950/30' : '' }}" wire:key="clean-{{ $answer->id }}">
                            <td class="py-2 pe-3">{{ $definition->cleaningGroups()[$answer->section]['label'] ?? $answer->section }}</td>
                            <td class="py-2 pe-3">{{ $labels[$answer->item_key] ?? $answer->item_key }}</td>
                            <td class="py-2">
                                @if ($answer->checked === null)
                                    —
                                @elseif ($answer->checked)
                                    {{ __('Done') }}
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
</div>
