<?php

use App\Enums\NurseQuestionnaireAnswer;
use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\NurseQuestionnaireResponse;
use App\Services\NotificationService;
use App\Services\NurseQuestionnaireService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Fill Questionnaire')] class extends Component
{
    #[Locked]
    public int $questionnaireId;

    /**
     * Selected answer keyed by question ID.
     *
     * @var array<int, string|null>
     */
    public array $answers = [];

    /**
     * Remarks keyed by question ID.
     *
     * @var array<int, string>
     */
    public array $remarks = [];

    public function mount(NurseQuestionnaire $questionnaire): void
    {
        abort_unless($questionnaire->is_active, 404);

        $this->questionnaireId = $questionnaire->id;

        if ($this->existingEntry !== null) {
            return;
        }

        foreach ($this->questions as $question) {
            $this->answers[$question->id] = null;
            $this->remarks[$question->id] = '';
        }
    }

    /**
     * Get the questionnaire being filled.
     */
    #[Computed]
    public function questionnaire(): NurseQuestionnaire
    {
        return NurseQuestionnaire::findOrFail($this->questionnaireId);
    }

    /**
     * Get the active questions for this questionnaire.
     *
     * @return Collection<int, \App\Models\NurseQuestionnaireQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return app(NurseQuestionnaireService::class)->activeQuestions($this->questionnaire);
    }

    /**
     * Get the current interval block.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    #[Computed]
    public function block(): array
    {
        return app(NurseQuestionnaireService::class)->currentBlock($this->questionnaire);
    }

    /**
     * Get the existing entry for the current block, if any.
     */
    #[Computed]
    public function existingEntry(): ?NurseQuestionnaireEntry
    {
        $block = $this->block;

        return NurseQuestionnaireEntry::with(['responses.question'])
            ->where('user_id', auth()->id())
            ->where('questionnaire_id', $this->questionnaireId)
            ->where('block_starts_at', $block['start'])
            ->where('block_ends_at', $block['end'])
            ->first();
    }

    /**
     * Set an answer for a question.
     */
    public function setAnswer(int $questionId, string $value): void
    {
        if (! in_array($value, ['yes', 'no'], true)) {
            return;
        }

        $this->answers[$questionId] = $value;

        if ($value !== 'no') {
            $this->remarks[$questionId] = '';
        }
    }

    /**
     * Submit the questionnaire for the current block.
     */
    public function submit(): void
    {
        if ($this->existingEntry !== null) {
            Flux::toast(variant: 'danger', text: __('This block has already been submitted.'));

            return;
        }

        if ($this->questions->isEmpty()) {
            Flux::toast(variant: 'danger', text: __('This form has no questions yet.'));

            return;
        }

        $rules = [];
        $messages = [];

        foreach ($this->questions as $question) {
            $rules["answers.{$question->id}"] = ['required', Rule::enum(NurseQuestionnaireAnswer::class)];
            $rules["remarks.{$question->id}"] = [
                Rule::requiredIf(fn () => ($this->answers[$question->id] ?? null) === NurseQuestionnaireAnswer::No->value),
                'nullable',
                'string',
                'max:2000',
            ];
            $messages["answers.{$question->id}.required"] = __('Please answer this question.');
            $messages["remarks.{$question->id}.required"] = __('A remark is required when you answer No.');
        }

        $validated = $this->validate($rules, $messages);
        $block = $this->block;

        $entry = DB::transaction(function () use ($validated, $block): NurseQuestionnaireEntry {
            $entry = NurseQuestionnaireEntry::create([
                'questionnaire_id' => $this->questionnaireId,
                'user_id' => auth()->id(),
                'block_starts_at' => $block['start'],
                'block_ends_at' => $block['end'],
                'submitted_at' => now(),
            ]);

            foreach ($this->questions as $question) {
                $answer = NurseQuestionnaireAnswer::from($validated['answers'][$question->id]);

                NurseQuestionnaireResponse::create([
                    'entry_id' => $entry->id,
                    'question_id' => $question->id,
                    'answer' => $answer,
                    'remarks' => $answer === NurseQuestionnaireAnswer::No
                        ? ($validated['remarks'][$question->id] ?? null)
                        : ($validated['remarks'][$question->id] ?: null),
                ]);
            }

            return $entry;
        });

        $entry->load(['questionnaire', 'responses.question']);

        $noResponses = $entry->responses->filter(
            fn (NurseQuestionnaireResponse $response) => $response->isNo()
        );

        if ($noResponses->isNotEmpty()) {
            app(NotificationService::class)->notifyNurseQuestionnaireSubmitted(auth()->user(), $entry, $noResponses);
        }

        Flux::toast(variant: 'success', text: __('Questionnaire submitted for :start - :end.', [
            'start' => $block['start']->format('H:i'),
            'end' => $block['end']->format('H:i'),
        ]));

        $this->reset(['answers', 'remarks']);
        unset($this->existingEntry);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ $this->questionnaire->name }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Block: :start - :end · :interval', [
                        'start' => $this->block['start']->format('H:i'),
                        'end' => $this->block['end']->format('H:i'),
                        'interval' => $this->questionnaire->intervalLabel(),
                    ]) }}
                </flux:text>
            </div>
            <flux:button variant="ghost" :href="route('incharge.questionnaires')" wire:navigate icon="arrow-left">
                {{ __('Back to questionnaires') }}
            </flux:button>
        </div>

        @if ($this->questionnaire->description)
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ $this->questionnaire->description }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->existingEntry)
            <flux:card class="border-s-4 border-s-emerald-500 dark:border-s-emerald-400">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:heading level="2" class="inline-flex items-center gap-2">
                            <flux:icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400" />
                            {{ __('Submitted Questionnaire') }}
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
                                <th class="py-2 pe-4 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Answer') }}</th>
                                <th class="py-2 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Remarks') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->existingEntry->responses as $response)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $response->isNo() ? 'bg-rose-50 dark:bg-rose-950/30' : '' }}" wire:key="response-{{ $response->id }}">
                                    <td class="py-3 pe-4 align-top">{{ $response->question->question_text }}</td>
                                    <td class="py-3 pe-4 align-top">
                                        @if ($response->isNo())
                                            <span class="status-badge-danger">
                                                <flux:icon name="x-circle" class="size-3.5" />
                                                {{ $response->answer->label() }}
                                            </span>
                                        @else
                                            <span class="status-badge-success">
                                                <flux:icon name="check-circle" class="size-3.5" />
                                                {{ $response->answer->label() }}
                                            </span>
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
                $answeredCount = collect($this->questions)->filter(fn ($q) => filled($this->answers[$q->id] ?? null))->count();
                $totalCount = $this->questions->count();
            @endphp
            <form wire:submit="submit" class="flex flex-col gap-6">
                <flux:card>
                    <div class="mb-4 space-y-2">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <flux:heading level="2">{{ __('Fill Questionnaire') }}</flux:heading>
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
                            {{ __('Answer Yes or No. If you select No, you must add a remark.') }}
                        </flux:text>
                    </div>

                    <div class="flex flex-col gap-6">
                        @foreach ($this->questions as $question)
                            @php
                                $answer = $this->answers[$question->id] ?? null;
                            @endphp
                            <div
                                class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
                                wire:key="question-{{ $question->id }}"
                                x-data="{}"
                            >
                                <flux:heading level="3" class="mb-4">{{ $question->question_text }}</flux:heading>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <button
                                        type="button"
                                        wire:click="setAnswer({{ $question->id }}, 'yes')"
                                        class="answer-card {{ $answer === 'yes' ? 'answer-card-yes' : '' }}"
                                        aria-pressed="{{ $answer === 'yes' ? 'true' : 'false' }}"
                                    >
                                        <flux:icon name="check-circle" class="size-5 {{ $answer === 'yes' ? 'text-emerald-600 dark:text-emerald-300' : 'text-zinc-400 dark:text-zinc-500' }}" />
                                        <span class="font-medium">{{ __('Yes') }}</span>
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="setAnswer({{ $question->id }}, 'no')"
                                        class="answer-card {{ $answer === 'no' ? 'answer-card-no' : '' }}"
                                        aria-pressed="{{ $answer === 'no' ? 'true' : 'false' }}"
                                    >
                                        <flux:icon name="x-circle" class="size-5 {{ $answer === 'no' ? 'text-rose-600 dark:text-rose-300' : 'text-zinc-400 dark:text-zinc-500' }}" />
                                        <span class="font-medium">{{ __('No') }}</span>
                                    </button>
                                </div>
                                <flux:error name="answers.{{ $question->id }}" />

                                <div
                                    x-show="$wire.answers[{{ $question->id }}] === 'no'"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 -translate-y-1"
                                >
                                    <flux:field class="mt-4 rounded-lg border border-rose-200 bg-rose-50/50 p-3 dark:border-rose-800 dark:bg-rose-950/20">
                                        <flux:label>{{ __('Remark') }}</flux:label>
                                        <flux:textarea wire:model="remarks.{{ $question->id }}" rows="2" required />
                                        <flux:description>{{ __('Required because you answered No.') }}</flux:description>
                                        <flux:error name="remarks.{{ $question->id }}" />
                                    </flux:field>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                        <flux:text class="text-sm text-zinc-500">
                            {{ __(':answered of :total answered', ['answered' => $answeredCount, 'total' => $totalCount]) }}
                        </flux:text>
                        <flux:button type="submit" variant="primary" icon="check">
                            {{ __('Submit Questionnaire') }}
                        </flux:button>
                    </div>
                </flux:card>
            </form>
        @endif
    </div>
</div>
