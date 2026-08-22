<?php

use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Services\NurseQuestionnaireService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Questionnaires')] class extends Component
{
    /**
     * Get active questionnaires for the incharge nurse.
     *
     * @return Collection<int, NurseQuestionnaire>
     */
    #[Computed]
    public function questionnaires(): Collection
    {
        return NurseQuestionnaire::query()
            ->active()
            ->withCount(['questions as active_questions_count' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the current block for a questionnaire.
     *
     * @return array{start: \Illuminate\Support\Carbon, end: \Illuminate\Support\Carbon}
     */
    public function blockFor(NurseQuestionnaire $questionnaire): array
    {
        return app(NurseQuestionnaireService::class)->currentBlock($questionnaire);
    }

    /**
     * Determine whether the current user has submitted the current block.
     */
    public function hasSubmitted(NurseQuestionnaire $questionnaire): bool
    {
        $block = $this->blockFor($questionnaire);

        return NurseQuestionnaireEntry::query()
            ->where('user_id', auth()->id())
            ->where('questionnaire_id', $questionnaire->id)
            ->where('block_starts_at', $block['start'])
            ->exists();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading level="1">{{ __('Questionnaires') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('Fill each form once per interval. A remark is required when you answer No.') }}
            </flux:text>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @forelse ($this->questionnaires as $questionnaire)
                @php
                    $block = $this->blockFor($questionnaire);
                    $submitted = $this->hasSubmitted($questionnaire);
                @endphp
                <flux:card
                    wire:key="questionnaire-{{ $questionnaire->id }}"
                    class="relative overflow-hidden {{ $submitted ? 'border-s-4 border-s-emerald-500 dark:border-s-emerald-400' : 'border-s-4 border-s-amber-500 dark:border-s-amber-400' }}"
                >
                    <div class="flex flex-col gap-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <flux:icon name="clipboard-document-list" class="mt-0.5 size-5 shrink-0 text-zinc-400 dark:text-zinc-500" />
                                <div>
                                    <flux:heading level="2">{{ $questionnaire->name }}</flux:heading>
                                    @if ($questionnaire->description)
                                        <flux:text class="mt-1 text-zinc-500">{{ $questionnaire->description }}</flux:text>
                                    @endif
                                </div>
                            </div>
                            @if ($submitted)
                                <span class="status-badge-success">
                                    <flux:icon name="check-circle" class="size-3.5" />
                                    {{ __('Submitted') }}
                                </span>
                            @else
                                <span class="status-badge-warning">
                                    <flux:icon name="clock" class="size-3.5" />
                                    {{ __('Due') }}
                                </span>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-500">
                            <span class="inline-flex items-center gap-1">
                                <flux:icon name="arrow-path" class="size-3.5" />
                                {{ $questionnaire->intervalLabel() }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <flux:icon name="clock" class="size-3.5" />
                                {{ __(':start - :end', ['start' => $block['start']->format('H:i'), 'end' => $block['end']->format('H:i')]) }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <flux:icon name="question-mark-circle" class="size-3.5" />
                                {{ __(':count questions', ['count' => $questionnaire->active_questions_count]) }}
                            </span>
                        </div>

                        @if ($questionnaire->active_questions_count === 0)
                            <flux:callout icon="information-circle" variant="subtle">
                                <flux:callout.text>{{ __('This form has no questions yet.') }}</flux:callout.text>
                            </flux:callout>
                        @else
                            <div class="flex justify-end">
                                <flux:button variant="primary" :href="route('incharge.questionnaire', $questionnaire)" wire:navigate icon="{{ $submitted ? 'eye' : 'pencil-square' }}">
                                    {{ $submitted ? __('View Submission') : __('Fill Form') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>
                </flux:card>
            @empty
                <flux:card class="md:col-span-2">
                    <div class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center">
                        <flux:icon name="clipboard-document-list" class="size-12 text-zinc-300 dark:text-zinc-600" />
                        <div>
                            <flux:heading level="3">{{ __('No questionnaires available') }}</flux:heading>
                            <flux:text class="text-zinc-500">{{ __('Check back later or contact your supervisor.') }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            @endforelse
        </div>
    </div>
</div>
