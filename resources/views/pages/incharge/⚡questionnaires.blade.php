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
                <flux:card wire:key="questionnaire-{{ $questionnaire->id }}">
                    <div class="flex flex-col gap-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <flux:heading level="2">{{ $questionnaire->name }}</flux:heading>
                                @if ($questionnaire->description)
                                    <flux:text class="mt-1 text-zinc-500">{{ $questionnaire->description }}</flux:text>
                                @endif
                            </div>
                            @if ($submitted)
                                <flux:badge size="sm" color="green">{{ __('Submitted') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Due') }}</flux:badge>
                            @endif
                        </div>

                        <div class="flex flex-col gap-1 text-sm text-zinc-500">
                            <span>{{ $questionnaire->intervalLabel() }}</span>
                            <span>{{ __('Current block: :start - :end', ['start' => $block['start']->format('H:i'), 'end' => $block['end']->format('H:i')]) }}</span>
                            <span>{{ __(':count questions', ['count' => $questionnaire->active_questions_count]) }}</span>
                        </div>

                        @if ($questionnaire->active_questions_count === 0)
                            <flux:text class="text-zinc-500">{{ __('This form has no questions yet.') }}</flux:text>
                        @else
                            <div class="flex justify-end">
                                <flux:button variant="primary" :href="route('incharge.questionnaire', $questionnaire)" wire:navigate>
                                    {{ $submitted ? __('View Submission') : __('Fill Form') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>
                </flux:card>
            @empty
                <flux:card class="md:col-span-2">
                    <flux:text class="text-center text-zinc-500">{{ __('No questionnaires are available right now.') }}</flux:text>
                </flux:card>
            @endforelse
        </div>
    </div>
</div>
