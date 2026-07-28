<?php

use App\Models\SupervisorChecklistEntry;
use App\Models\SupervisorChecklistResponse;
use App\Services\NotificationService;
use App\Services\SupervisorChecklistService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supervisor Checklist')] class extends Component
{
    /**
     * Selected option ID keyed by question ID.
     *
     * @var array<int, int|null>
     */
    public array $selectedOptions = [];

    /**
     * Remarks keyed by question ID.
     *
     * @var array<int, string>
     */
    public array $remarks = [];

    /**
     * Get the active questions with their options.
     *
     * @return Collection<int, \App\Models\SupervisorChecklistQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return app(SupervisorChecklistService::class)->activeQuestions();
    }

    /**
     * Get the current two-hour block.
     *
     * @return array<string, Carbon>
     */
    #[Computed]
    public function block(): array
    {
        return app(SupervisorChecklistService::class)->currentBlock();
    }

    /**
     * Get the existing entry for the current block, if any.
     */
    #[Computed]
    public function existingEntry(): ?SupervisorChecklistEntry
    {
        $block = $this->block;

        return SupervisorChecklistEntry::with(['responses.question', 'responses.options'])
            ->where('user_id', auth()->id())
            ->where('block_starts_at', $block['start'])
            ->where('block_ends_at', $block['end'])
            ->first();
    }

    /**
     * Initialize form fields when no entry exists.
     */
    public function mount(): void
    {
        if ($this->existingEntry !== null) {
            return;
        }

        foreach ($this->questions as $question) {
            $this->selectedOptions[$question->id] = null;
            $this->remarks[$question->id] = '';
        }
    }

    /**
     * Submit the checklist for the current block.
     */
    public function submit(): void
    {
        if ($this->existingEntry !== null) {
            Flux::toast(variant: 'danger', text: __('This block has already been submitted.'));

            return;
        }

        $rules = [];
        foreach ($this->questions as $question) {
            $rules["selectedOptions.{$question->id}"] = ['nullable', 'integer', 'exists:supervisor_checklist_options,id'];
            $rules["remarks.{$question->id}"] = ['nullable', 'string', 'max:2000'];
        }

        $validated = $this->validate($rules);
        $block = $this->block;

        $entry = SupervisorChecklistEntry::create([
            'user_id' => auth()->id(),
            'block_starts_at' => $block['start'],
            'block_ends_at' => $block['end'],
            'submitted_at' => now(),
        ]);

        foreach ($this->questions as $question) {
            $response = SupervisorChecklistResponse::create([
                'entry_id' => $entry->id,
                'question_id' => $question->id,
                'remarks' => $validated['remarks'][$question->id] ?? null,
            ]);

            $optionId = $validated['selectedOptions'][$question->id] ?? null;
            if ($optionId !== null) {
                $response->options()->attach($optionId);
            }
        }

        $entry->load(['responses.question', 'responses.options']);

        $noResponses = $entry->responses->filter(
            fn (SupervisorChecklistResponse $response) => $response->options->contains('is_no', true)
        );

        if ($noResponses->isNotEmpty()) {
            app(NotificationService::class)->notifySupervisorChecklistSubmitted(auth()->user(), $entry, $noResponses);
        }

        Flux::toast(variant: 'success', text: __('Checklist submitted for :start - :end.', [
            'start' => $block['start']->format('H:i'),
            'end' => $block['end']->format('H:i'),
        ]));

        $this->reset(['selectedOptions', 'remarks']);
    }
}; ?>

<div class="print:p-0">
    <div class="flex h-full w-full flex-1 flex-col gap-6 print:gap-2">
        <div class="flex flex-col gap-2 print:hidden sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Supervisor Checklist') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Block: :start - :end', ['start' => $this->block['start']->format('H:i'), 'end' => $this->block['end']->format('H:i')]) }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="printer" onclick="window.print()">
                {{ __('Print') }}
            </flux:button>
        </div>

        @if ($this->existingEntry)
            <flux:card>
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Submitted Checklist') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Submitted at :time', ['time' => $this->existingEntry->submitted_at->format('Y-m-d H:i:s')]) }}
                        </flux:text>
                    </div>
                    <flux:badge size="sm" color="green">{{ __('Completed') }}</flux:badge>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="py-2 pe-4 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Question') }}</th>
                                <th class="py-2 pe-4 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Selected Options') }}</th>
                                <th class="py-2 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Remarks') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->existingEntry->responses as $response)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="response-{{ $response->id }}">
                                    <td class="py-3 pe-4 align-top">{{ $response->question->question_text }}</td>
                                    <td class="py-3 pe-4 align-top">
                                        @if ($response->options->isEmpty())
                                            <span class="text-zinc-400">-</span>
                                        @else
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($response->options as $option)
                                                    <flux:badge size="sm" color="blue">{{ $option->option_text }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-3 align-top">{{ $response->remarks ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>
        @else
            <form wire:submit="submit" class="flex flex-col gap-6 print:gap-2">
                <flux:card>
                    <div class="mb-4 print:hidden">
                        <flux:heading level="2">{{ __('Fill Checklist') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Select the applicable options and add remarks for each question.') }}
                        </flux:text>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="py-2 pe-4 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Question') }}</th>
                                    <th class="py-2 pe-4 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Options') }}</th>
                                    <th class="py-2 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Remarks') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->questions as $question)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="question-{{ $question->id }}">
                                        <td class="py-3 pe-4 align-top">{{ $question->question_text }}</td>
                                        <td class="py-3 pe-4 align-top">
                                            <div class="flex flex-col gap-2">
                                                @foreach ($question->options->where('is_active', true) as $option)
                                                    <label class="flex items-center gap-2">
                                                        <input
                                                            type="radio"
                                                            wire:model="selectedOptions.{{ $question->id }}"
                                                            value="{{ $option->id }}"
                                                            class="border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                                                        />
                                                        <span>{{ $option->option_text }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            <flux:error name="selectedOptions.{{ $question->id }}" />
                                        </td>
                                        <td class="py-3 align-top">
                                            <flux:textarea wire:model="remarks.{{ $question->id }}" rows="2" class="w-full min-w-[200px]" />
                                            <flux:error name="remarks.{{ $question->id }}" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 print:hidden">
                        <flux:button type="submit" variant="primary">
                            {{ __('Submit Checklist') }}
                        </flux:button>
                    </div>
                </flux:card>
            </form>
        @endif
    </div>
</div>
