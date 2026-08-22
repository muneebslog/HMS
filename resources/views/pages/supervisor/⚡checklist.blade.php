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

new #[Title('Checklist')] class extends Component
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
     * Select an option for a question.
     */
    public function setOption(int $questionId, ?int $optionId): void
    {
        $this->selectedOptions[$questionId] = $optionId;
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
                <flux:heading level="1">{{ __('Checklist') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Block: :start - :end', ['start' => $this->block['start']->format('H:i'), 'end' => $this->block['end']->format('H:i')]) }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="printer" onclick="window.print()">
                {{ __('Print') }}
            </flux:button>
        </div>

        @if ($this->existingEntry)
            <flux:card class="border-s-4 border-s-emerald-500 dark:border-s-emerald-400">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:heading level="2" class="inline-flex items-center gap-2">
                            <flux:icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400" />
                            {{ __('Submitted Checklist') }}
                        </flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Submitted at :time', ['time' => $this->existingEntry->submitted_at->format('Y-m-d H:i:s')]) }}
                        </flux:text>
                    </div>
                    <span class="status-badge-success">
                        <flux:icon name="check-circle" class="size-3.5" />
                        {{ __('Completed') }}
                    </span>
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
                                @php
                                    $hasNoOption = $response->options->contains('is_no', true);
                                @endphp
                                <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $hasNoOption ? 'bg-rose-50 dark:bg-rose-950/30' : '' }}" wire:key="response-{{ $response->id }}">
                                    <td class="py-3 pe-4 align-top">{{ $response->question->question_text }}</td>
                                    <td class="py-3 pe-4 align-top">
                                        @if ($response->options->isEmpty())
                                            <span class="text-zinc-400">—</span>
                                        @else
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($response->options as $option)
                                                    @if ($option->is_no)
                                                        <span class="status-badge-danger">
                                                            <flux:icon name="x-circle" class="size-3" />
                                                            {{ $option->option_text }}
                                                        </span>
                                                    @else
                                                        <span class="status-badge-success">
                                                            <flux:icon name="check-circle" class="size-3" />
                                                            {{ $option->option_text }}
                                                        </span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-3 align-top">
                                        @if ($response->remarks)
                                            {{ $response->remarks }}
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>
        @else
            @php
                $answeredCount = collect($this->questions)->filter(fn ($q) => filled($this->selectedOptions[$q->id] ?? null))->count();
                $totalCount = $this->questions->count();
            @endphp
            <form wire:submit="submit" class="flex flex-col gap-6 print:gap-2">
                <flux:card>
                    <div class="mb-4 space-y-2 print:hidden">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <flux:heading level="2">{{ __('Fill Checklist') }}</flux:heading>
                            <flux:text class="text-sm font-medium text-zinc-600 dark:text-zinc-300">
                                {{ __(':answered of :total answered', ['answered' => $answeredCount, 'total' => $totalCount]) }}
                            </flux:text>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div
                                class="h-full rounded-full bg-primary transition-all duration-300"
                                style="width: {{ $totalCount > 0 ? ($answeredCount / $totalCount) * 100 : 0 }}%"
                                aria-hidden="true"
                            ></div>
                        </div>
                        <flux:text class="text-zinc-500">
                            {{ __('Select the applicable option for each question. Add remarks where needed.') }}
                        </flux:text>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($this->questions as $question)
                            @php
                                $selectedOptionId = $this->selectedOptions[$question->id] ?? null;
                            @endphp
                            <fieldset
                                class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
                                wire:key="question-{{ $question->id }}"
                            >
                                <legend class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ $question->question_text }}
                                </legend>

                                <div class="flex flex-col gap-2">
                                    @foreach ($question->options->where('is_active', true) as $option)
                                        @php
                                            $isSelected = $selectedOptionId === $option->id;
                                            $isNoOption = $option->is_no;
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="setOption({{ $question->id }}, {{ $option->id }})"
                                            class="answer-card {{ $isSelected ? ($isNoOption ? 'answer-card-no' : 'answer-card-yes') : '' }}"
                                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                        >
                                            @if ($isNoOption)
                                                <flux:icon name="x-circle" class="size-5 {{ $isSelected ? 'text-rose-600 dark:text-rose-300' : 'text-zinc-400 dark:text-zinc-500' }}" />
                                            @else
                                                <flux:icon name="check-circle" class="size-5 {{ $isSelected ? 'text-emerald-600 dark:text-emerald-300' : 'text-zinc-400 dark:text-zinc-500' }}" />
                                            @endif
                                            <span class="font-medium">{{ $option->option_text }}</span>
                                        </button>
                                    @endforeach
                                </div>
                                <flux:error name="selectedOptions.{{ $question->id }}" />

                                <flux:field class="mt-3">
                                    <flux:label class="text-xs">{{ __('Remarks') }}</flux:label>
                                    <flux:textarea wire:model="remarks.{{ $question->id }}" rows="2" class="w-full" />
                                    <flux:error name="remarks.{{ $question->id }}" />
                                </flux:field>
                            </fieldset>
                        @endforeach
                    </div>

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
                        <flux:text class="text-sm text-zinc-500">
                            {{ __(':answered of :total answered', ['answered' => $answeredCount, 'total' => $totalCount]) }}
                        </flux:text>
                        <flux:button type="submit" variant="primary" icon="check">
                            {{ __('Submit Checklist') }}
                        </flux:button>
                    </div>
                </flux:card>
            </form>
        @endif
    </div>
</div>
