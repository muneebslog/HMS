<?php

use App\Enums\UserRole;
use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\User;
use App\Services\NurseQuestionnaireService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nurse Questionnaire Submissions')] class extends Component
{
    public ?int $selectedQuestionnaireId = null;

    public ?int $selectedNurseId = null;

    public string $selectedDate = '';

    public ?string $expandedBlock = null;

    public function mount(): void
    {
        $this->selectedDate = now()->format('Y-m-d');
    }

    /**
     * Get all questionnaires.
     *
     * @return Collection<int, NurseQuestionnaire>
     */
    #[Computed]
    public function questionnaires(): Collection
    {
        return NurseQuestionnaire::query()->orderBy('name')->get();
    }

    /**
     * Get all incharge nurse users.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function nurses(): Collection
    {
        return User::where('role', UserRole::InchargeNurse)->orderBy('name')->get();
    }

    /**
     * Get the selected questionnaire.
     */
    #[Computed]
    public function questionnaire(): ?NurseQuestionnaire
    {
        if ($this->selectedQuestionnaireId === null) {
            return null;
        }

        return NurseQuestionnaire::find($this->selectedQuestionnaireId);
    }

    /**
     * Get the selected incharge nurse.
     */
    #[Computed]
    public function nurse(): ?User
    {
        if ($this->selectedNurseId === null) {
            return null;
        }

        return User::where('role', UserRole::InchargeNurse)->find($this->selectedNurseId);
    }

    /**
     * Get the blocks for the selected date.
     *
     * @return \Illuminate\Support\Collection<int, array{start: Carbon, end: Carbon}>
     */
    #[Computed]
    public function blocks(): \Illuminate\Support\Collection
    {
        if ($this->questionnaire === null) {
            return collect();
        }

        return app(NurseQuestionnaireService::class)->blocksForDate(
            $this->questionnaire,
            Carbon::parse($this->selectedDate)
        );
    }

    /**
     * Get entries for the selected nurse, questionnaire, and date.
     *
     * @return Collection<int, NurseQuestionnaireEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        if ($this->nurse === null || $this->questionnaire === null) {
            return new Collection;
        }

        return NurseQuestionnaireEntry::with(['responses.question'])
            ->where('user_id', $this->nurse->id)
            ->where('questionnaire_id', $this->questionnaire->id)
            ->whereDate('block_starts_at', $this->selectedDate)
            ->orderBy('block_starts_at')
            ->get();
    }

    /**
     * Find the entry for a given block.
     *
     * @param  array{start: Carbon, end: Carbon}  $block
     */
    public function entryForBlock(array $block): ?NurseQuestionnaireEntry
    {
        return $this->entries->first(
            fn (NurseQuestionnaireEntry $entry) => $entry->block_starts_at->equalTo($block['start'])
        );
    }

    /**
     * Toggle the expanded block detail view.
     */
    public function toggleBlock(string $blockKey): void
    {
        $this->expandedBlock = $this->expandedBlock === $blockKey ? null : $blockKey;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Nurse Questionnaire Submissions') }}</flux:heading>
        </div>

        <flux:card>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:field>
                    <flux:label>{{ __('Questionnaire') }}</flux:label>
                    <flux:select wire:model.live="selectedQuestionnaireId">
                        <option value="">{{ __('Select a questionnaire') }}</option>
                        @foreach ($this->questionnaires as $questionnaire)
                            <option value="{{ $questionnaire->id }}">{{ $questionnaire->name }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Incharge Nurse') }}</flux:label>
                    <flux:select wire:model.live="selectedNurseId">
                        <option value="">{{ __('Select a nurse') }}</option>
                        @foreach ($this->nurses as $nurse)
                            <option value="{{ $nurse->id }}">{{ $nurse->name }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input type="date" wire:model.live="selectedDate" />
                </flux:field>
            </div>
        </flux:card>

        @if ($this->questionnaire && $this->nurse)
            <flux:card>
                <flux:heading level="2" class="mb-4">
                    {{ __(':form — :name — :date', [
                        'form' => $this->questionnaire->name,
                        'name' => $this->nurse->name,
                        'date' => Carbon::parse($this->selectedDate)->format('M j, Y'),
                    ]) }}
                </flux:heading>
                <flux:text class="mb-4 text-zinc-500">{{ $this->questionnaire->intervalLabel() }}</flux:text>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Block') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Submitted At') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->blocks as $block)
                            @php
                                $entry = $this->entryForBlock($block);
                                $blockKey = $block['start']->format('H:i');
                            @endphp
                            <flux:table.row wire:key="block-{{ $blockKey }}">
                                <flux:table.cell>
                                    {{ $block['start']->format('H:i') }} - {{ $block['end']->format('H:i') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($entry)
                                        <flux:badge size="sm" color="green">{{ __('Submitted') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">{{ __('Missing') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $entry?->submitted_at->format('Y-m-d H:i:s') ?? '-' }}
                                </flux:table.cell>
                                <flux:table.cell class="text-right">
                                    @if ($entry)
                                        <flux:button size="sm" variant="ghost" icon="eye" wire:click="toggleBlock('{{ $blockKey }}')">
                                            {{ $expandedBlock === $blockKey ? __('Hide') : __('View') }}
                                        </flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>

                            @if ($expandedBlock === $blockKey && $entry)
                                <flux:table.row wire:key="block-{{ $blockKey }}-detail">
                                    <flux:table.cell colspan="4" class="bg-zinc-50 dark:bg-zinc-900/50">
                                        <div class="py-2">
                                            <flux:heading level="3" class="mb-3">{{ __('Responses') }}</flux:heading>
                                            <div class="overflow-x-auto">
                                                <table class="w-full border-collapse text-sm">
                                                    <thead>
                                                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                                            <th class="py-2 pe-4 text-start font-medium">{{ __('Question') }}</th>
                                                            <th class="py-2 pe-4 text-start font-medium">{{ __('Answer') }}</th>
                                                            <th class="py-2 text-start font-medium">{{ __('Remarks') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($entry->responses as $response)
                                                            <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $response->isNo() ? 'bg-red-50 text-red-900 dark:bg-red-950/30 dark:text-red-100' : '' }}" wire:key="response-{{ $response->id }}">
                                                                <td class="py-2 pe-4 align-top">{{ $response->question->question_text }}</td>
                                                                <td class="py-2 pe-4 align-top">
                                                                    <flux:badge size="sm" color="{{ $response->isNo() ? 'red' : 'green' }}">
                                                                        {{ $response->answer->label() }}
                                                                    </flux:badge>
                                                                </td>
                                                                <td class="py-2 align-top">{{ $response->remarks ?: '-' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endif
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @else
            <flux:card>
                <flux:text class="text-center text-zinc-500">{{ __('Select a questionnaire and incharge nurse to view the daily summary.') }}</flux:text>
            </flux:card>
        @endif
    </div>
</div>
