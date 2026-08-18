<?php

use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireQuestion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nurse Questionnaires')] class extends Component
{
    public bool $showQuestionnaireModal = false;

    public ?int $editingQuestionnaireId = null;

    public string $name = '';

    public string $description = '';

    public int $intervalHours = 2;

    public bool $questionnaireIsActive = true;

    public bool $showQuestionsModal = false;

    public ?int $managingQuestionnaireId = null;

    public ?int $editingQuestionId = null;

    public string $questionText = '';

    public int $questionSortOrder = 0;

    public bool $questionIsActive = true;

    /**
     * Get all questionnaires with question counts.
     *
     * @return Collection<int, NurseQuestionnaire>
     */
    #[Computed]
    public function questionnaires(): Collection
    {
        return NurseQuestionnaire::query()
            ->withCount('questions')
            ->latest()
            ->get();
    }

    /**
     * Get the questionnaire whose questions are being managed.
     */
    #[Computed]
    public function managingQuestionnaire(): ?NurseQuestionnaire
    {
        if ($this->managingQuestionnaireId === null) {
            return null;
        }

        return NurseQuestionnaire::with('questions')->find($this->managingQuestionnaireId);
    }

    /**
     * Open the modal to create a questionnaire.
     */
    public function createQuestionnaire(): void
    {
        $this->resetQuestionnaireForm();
        $this->showQuestionnaireModal = true;
    }

    /**
     * Open the modal to edit a questionnaire.
     */
    public function editQuestionnaire(int $id): void
    {
        $questionnaire = NurseQuestionnaire::findOrFail($id);

        $this->editingQuestionnaireId = $questionnaire->id;
        $this->name = $questionnaire->name;
        $this->description = $questionnaire->description ?? '';
        $this->intervalHours = $questionnaire->interval_hours;
        $this->questionnaireIsActive = $questionnaire->is_active;
        $this->showQuestionnaireModal = true;
    }

    /**
     * Persist the questionnaire form.
     */
    public function saveQuestionnaire(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'intervalHours' => ['required', 'integer', 'min:1', 'max:24'],
            'questionnaireIsActive' => ['boolean'],
        ]);

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'interval_hours' => $validated['intervalHours'],
            'is_active' => $validated['questionnaireIsActive'],
        ];

        if ($this->editingQuestionnaireId) {
            NurseQuestionnaire::findOrFail($this->editingQuestionnaireId)->update($data);
            Flux::toast(variant: 'success', text: __('Questionnaire updated.'));
            $this->closeQuestionnaireModal();

            return;
        }

        $questionnaire = NurseQuestionnaire::create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        Flux::toast(variant: 'success', text: __('Questionnaire created. Add questions next.'));
        $this->closeQuestionnaireModal();
        $this->manageQuestions($questionnaire->id);
    }

    /**
     * Delete a questionnaire and its questions.
     */
    public function deleteQuestionnaire(int $id): void
    {
        NurseQuestionnaire::findOrFail($id)->delete();

        Flux::toast(variant: 'success', text: __('Questionnaire deleted.'));
    }

    /**
     * Close the questionnaire modal.
     */
    public function closeQuestionnaireModal(): void
    {
        $this->showQuestionnaireModal = false;
        $this->resetQuestionnaireForm();
    }

    /**
     * Reset the questionnaire form fields.
     */
    private function resetQuestionnaireForm(): void
    {
        $this->editingQuestionnaireId = null;
        $this->name = '';
        $this->description = '';
        $this->intervalHours = 2;
        $this->questionnaireIsActive = true;
        $this->resetErrorBag();
    }

    /**
     * Open the questions management modal for a questionnaire.
     */
    public function manageQuestions(int $questionnaireId): void
    {
        $this->managingQuestionnaireId = $questionnaireId;
        $this->resetQuestionForm();
        $this->showQuestionsModal = true;
    }

    /**
     * Open the question form to create a new question.
     */
    public function createQuestion(): void
    {
        $this->resetQuestionForm();
        $this->showQuestionsModal = true;
    }

    /**
     * Load a question into the question form.
     */
    public function editQuestion(int $questionId): void
    {
        $question = NurseQuestionnaireQuestion::findOrFail($questionId);

        $this->editingQuestionId = $question->id;
        $this->questionText = $question->question_text;
        $this->questionSortOrder = $question->sort_order;
        $this->questionIsActive = $question->is_active;
    }

    /**
     * Persist the question form.
     */
    public function saveQuestion(): void
    {
        $validated = $this->validate([
            'questionText' => ['required', 'string', 'max:1000'],
            'questionSortOrder' => ['required', 'integer', 'min:0'],
            'questionIsActive' => ['boolean'],
        ]);

        $data = [
            'questionnaire_id' => $this->managingQuestionnaireId,
            'question_text' => $validated['questionText'],
            'sort_order' => $validated['questionSortOrder'],
            'is_active' => $validated['questionIsActive'],
        ];

        if ($this->editingQuestionId) {
            NurseQuestionnaireQuestion::findOrFail($this->editingQuestionId)->update($data);
            Flux::toast(variant: 'success', text: __('Question updated.'));
        } else {
            NurseQuestionnaireQuestion::create($data);
            Flux::toast(variant: 'success', text: __('Question created.'));
        }

        $this->resetQuestionForm();
        unset($this->managingQuestionnaire);
    }

    /**
     * Delete a question.
     */
    public function deleteQuestion(int $questionId): void
    {
        NurseQuestionnaireQuestion::findOrFail($questionId)->delete();

        Flux::toast(variant: 'success', text: __('Question deleted.'));
        unset($this->managingQuestionnaire);
    }

    /**
     * Close the questions modal.
     */
    public function closeQuestionsModal(): void
    {
        $this->showQuestionsModal = false;
        $this->managingQuestionnaireId = null;
        $this->resetQuestionForm();
    }

    /**
     * Reset the question form fields.
     */
    private function resetQuestionForm(): void
    {
        $this->editingQuestionId = null;
        $this->questionText = '';
        $this->questionSortOrder = 0;
        $this->questionIsActive = true;
        $this->resetErrorBag();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Nurse Questionnaires') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Create yes/no forms for incharge nurses and set how often they must be filled.') }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="createQuestionnaire">
                {{ __('Add Questionnaire') }}
            </flux:button>
        </div>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Fill Interval') }}</flux:table.column>
                    <flux:table.column>{{ __('Questions') }}</flux:table.column>
                    <flux:table.column>{{ __('Active') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->questionnaires as $questionnaire)
                        <flux:table.row wire:key="questionnaire-{{ $questionnaire->id }}">
                            <flux:table.cell>
                                <div class="flex flex-col gap-1">
                                    <span>{{ $questionnaire->name }}</span>
                                    @if ($questionnaire->description)
                                        <span class="text-sm text-zinc-500">{{ $questionnaire->description }}</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $questionnaire->intervalLabel() }}</flux:table.cell>
                            <flux:table.cell>{{ $questionnaire->questions_count }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($questionnaire->is_active)
                                    <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button size="sm" variant="ghost" icon="list-bullet" wire:click="manageQuestions({{ $questionnaire->id }})" class="me-2">
                                    {{ __('Questions') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editQuestionnaire({{ $questionnaire->id }})" class="me-2" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteQuestionnaire({{ $questionnaire->id }})" wire:confirm="{{ __('Are you sure you want to delete this questionnaire?') }}" />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No questionnaires found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showQuestionnaireModal" class="w-full max-w-lg">
        <flux:heading level="2">
            {{ $editingQuestionnaireId ? __('Edit Questionnaire') : __('Add Questionnaire') }}
        </flux:heading>

        <form wire:submit="saveQuestionnaire" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" type="text" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="description" rows="2" />
                <flux:error name="description" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Fill every (hours)') }}</flux:label>
                <flux:input wire:model="intervalHours" type="number" min="1" max="24" step="1" required />
                <flux:description>{{ __('Incharge nurses can submit this form once per interval, for example every 2 hours.') }}</flux:description>
                <flux:error name="intervalHours" />
            </flux:field>

            <flux:field>
                <flux:switch wire:model="questionnaireIsActive" :label="__('Active')" />
                <flux:error name="questionnaireIsActive" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeQuestionnaireModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingQuestionnaireId ? __('Update') : __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showQuestionsModal" class="w-full max-w-2xl">
        @if ($this->managingQuestionnaire)
            <flux:heading level="2">{{ __('Questions for :name', ['name' => $this->managingQuestionnaire->name]) }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Each question is answered Yes or No. A remark is required when No is selected.') }}</flux:text>

            <div class="mt-6 space-y-6">
                <form wire:submit="saveQuestion" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading level="3">{{ $editingQuestionId ? __('Edit Question') : __('Add Question') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Question') }}</flux:label>
                        <flux:textarea wire:model="questionText" rows="2" required />
                        <flux:error name="questionText" />
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Sort Order') }}</flux:label>
                            <flux:input wire:model="questionSortOrder" type="number" min="0" step="1" required />
                            <flux:error name="questionSortOrder" />
                        </flux:field>

                        <flux:field>
                            <flux:switch wire:model="questionIsActive" :label="__('Active')" />
                            <flux:error name="questionIsActive" />
                        </flux:field>
                    </div>

                    <div class="flex justify-end gap-3">
                        @if ($editingQuestionId)
                            <flux:button type="button" variant="ghost" wire:click="createQuestion">
                                {{ __('Cancel Edit') }}
                            </flux:button>
                        @endif
                        <flux:button type="submit" variant="primary">
                            {{ $editingQuestionId ? __('Update') : __('Add') }}
                        </flux:button>
                    </div>
                </form>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Question') }}</flux:table.column>
                        <flux:table.column>{{ __('Sort Order') }}</flux:table.column>
                        <flux:table.column>{{ __('Active') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->managingQuestionnaire->questions as $question)
                            <flux:table.row wire:key="question-{{ $question->id }}">
                                <flux:table.cell>{{ $question->question_text }}</flux:table.cell>
                                <flux:table.cell>{{ $question->sort_order }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($question->is_active)
                                        <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editQuestion({{ $question->id }})" class="me-2" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteQuestion({{ $question->id }})" wire:confirm="{{ __('Are you sure you want to delete this question?') }}" />
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                    {{ __('No questions found.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </flux:modal>
</div>
