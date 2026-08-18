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
            <flux:card>
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Submitted Questionnaire') }}</flux:heading>
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
                                <th class="py-2 pe-4 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Answer') }}</th>
                                <th class="py-2 text-start font-medium text-zinc-700 dark:text-zinc-300">{{ __('Remarks') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->existingEntry->responses as $response)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $response->isNo() ? 'bg-red-50 dark:bg-red-950/30' : '' }}" wire:key="response-{{ $response->id }}">
                                    <td class="py-3 pe-4 align-top">{{ $response->question->question_text }}</td>
                                    <td class="py-3 pe-4 align-top">
                                        <flux:badge size="sm" color="{{ $response->isNo() ? 'red' : 'green' }}">
                                            {{ $response->answer->label() }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-3 align-top">{{ $response->remarks ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>
        @else
            <form wire:submit="submit" class="flex flex-col gap-6">
                <flux:card>
                    <div class="mb-4">
                        <flux:heading level="2">{{ __('Fill Questionnaire') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Answer Yes or No. If you select No, you must add a remark.') }}
                        </flux:text>
                    </div>

                    <div class="flex flex-col gap-6">
                        @foreach ($this->questions as $question)
                            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="question-{{ $question->id }}">
                                <flux:heading level="3" class="mb-4">{{ $question->question_text }}</flux:heading>

                                <flux:radio.group wire:model.live="answers.{{ $question->id }}" class="flex flex-wrap gap-6">
                                    <flux:radio value="yes">{{ __('Yes') }}</flux:radio>
                                    <flux:radio value="no">{{ __('No') }}</flux:radio>
                                </flux:radio.group>
                                <flux:error name="answers.{{ $question->id }}" />

                                @if (($answers[$question->id] ?? null) === 'no')
                                    <flux:field class="mt-4">
                                        <flux:label>{{ __('Remark') }}</flux:label>
                                        <flux:textarea wire:model="remarks.{{ $question->id }}" rows="2" required />
                                        <flux:description>{{ __('Required because you answered No.') }}</flux:description>
                                        <flux:error name="remarks.{{ $question->id }}" />
                                    </flux:field>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6">
                        <flux:button type="submit" variant="primary">
                            {{ __('Submit Questionnaire') }}
                        </flux:button>
                    </div>
                </flux:card>
            </form>
        @endif
    </div>
</div>
