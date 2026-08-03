<?php

use App\Models\SupervisorChecklistOption;
use App\Models\SupervisorChecklistQuestion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Checklist Questions')] class extends Component
{
    public bool $showQuestionModal = false;

    public ?int $editingQuestionId = null;

    public string $questionText = '';

    public int $questionSortOrder = 0;

    public bool $questionIsActive = true;

    public bool $showOptionsModal = false;

    public ?int $managingQuestionId = null;

    public ?int $editingOptionId = null;

    public string $optionText = '';

    public bool $optionIsNo = false;

    public int $optionSortOrder = 0;

    public bool $optionIsActive = true;

    /**
     * Get all questions ordered by sort order.
     *
     * @return Collection<int, SupervisorChecklistQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return SupervisorChecklistQuestion::with('options')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get the question whose options are being managed.
     */
    #[Computed]
    public function managingQuestion(): ?SupervisorChecklistQuestion
    {
        if ($this->managingQuestionId === null) {
            return null;
        }

        return SupervisorChecklistQuestion::with('options')->find($this->managingQuestionId);
    }

    /**
     * Open the modal to create a question.
     */
    public function createQuestion(): void
    {
        $this->resetQuestionForm();
        $this->showQuestionModal = true;
    }

    /**
     * Open the modal to edit a question.
     */
    public function editQuestion(int $id): void
    {
        $question = SupervisorChecklistQuestion::findOrFail($id);

        $this->editingQuestionId = $question->id;
        $this->questionText = $question->question_text;
        $this->questionSortOrder = $question->sort_order;
        $this->questionIsActive = $question->is_active;
        $this->showQuestionModal = true;
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
            'question_text' => $validated['questionText'],
            'sort_order' => $validated['questionSortOrder'],
            'is_active' => $validated['questionIsActive'],
        ];

        if ($this->editingQuestionId) {
            SupervisorChecklistQuestion::findOrFail($this->editingQuestionId)->update($data);
            Flux::toast(variant: 'success', text: __('Question updated.'));
        } else {
            $question = SupervisorChecklistQuestion::create($data);

            $question->options()->createMany([
                ['option_text' => __('Yes'), 'is_no' => false, 'sort_order' => 0, 'is_active' => true],
                ['option_text' => __('No'), 'is_no' => true, 'sort_order' => 1, 'is_active' => true],
            ]);

            Flux::toast(variant: 'success', text: __('Question created with Yes/No options.'));
        }

        $this->closeQuestionModal();
    }

    /**
     * Delete a question and its options.
     */
    public function deleteQuestion(int $id): void
    {
        SupervisorChecklistQuestion::findOrFail($id)->delete();

        Flux::toast(variant: 'success', text: __('Question deleted.'));
    }

    /**
     * Close the question modal.
     */
    public function closeQuestionModal(): void
    {
        $this->showQuestionModal = false;
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

    /**
     * Open the options management modal for a question.
     */
    public function manageOptions(int $questionId): void
    {
        $this->managingQuestionId = $questionId;
        $this->resetOptionForm();
        $this->showOptionsModal = true;
    }

    /**
     * Open the option form to create a new option.
     */
    public function createOption(): void
    {
        $this->resetOptionForm();
        $this->showOptionsModal = true;
    }

    /**
     * Load an option into the option form.
     */
    public function editOption(int $optionId): void
    {
        $option = SupervisorChecklistOption::findOrFail($optionId);

        $this->editingOptionId = $option->id;
        $this->optionText = $option->option_text;
        $this->optionIsNo = $option->is_no;
        $this->optionSortOrder = $option->sort_order;
        $this->optionIsActive = $option->is_active;
    }

    /**
     * Persist the option form.
     */
    public function saveOption(): void
    {
        $validated = $this->validate([
            'optionText' => ['required', 'string', 'max:1000'],
            'optionIsNo' => ['boolean'],
            'optionSortOrder' => ['required', 'integer', 'min:0'],
            'optionIsActive' => ['boolean'],
        ]);

        $data = [
            'question_id' => $this->managingQuestionId,
            'option_text' => $validated['optionText'],
            'is_no' => $validated['optionIsNo'],
            'sort_order' => $validated['optionSortOrder'],
            'is_active' => $validated['optionIsActive'],
        ];

        if ($this->editingOptionId) {
            SupervisorChecklistOption::findOrFail($this->editingOptionId)->update($data);
            Flux::toast(variant: 'success', text: __('Option updated.'));
        } else {
            SupervisorChecklistOption::create($data);
            Flux::toast(variant: 'success', text: __('Option created.'));
        }

        $this->resetOptionForm();
    }

    /**
     * Delete an option.
     */
    public function deleteOption(int $optionId): void
    {
        SupervisorChecklistOption::findOrFail($optionId)->delete();

        Flux::toast(variant: 'success', text: __('Option deleted.'));
    }

    /**
     * Close the options modal.
     */
    public function closeOptionsModal(): void
    {
        $this->showOptionsModal = false;
        $this->managingQuestionId = null;
        $this->resetOptionForm();
    }

    /**
     * Reset the option form fields.
     */
    private function resetOptionForm(): void
    {
        $this->editingOptionId = null;
        $this->optionText = '';
        $this->optionIsNo = false;
        $this->optionSortOrder = 0;
        $this->optionIsActive = true;
        $this->resetErrorBag();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Checklist Questions') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="createQuestion">
                {{ __('Add Question') }}
            </flux:button>
        </div>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Question') }}</flux:table.column>
                    <flux:table.column>{{ __('Sort Order') }}</flux:table.column>
                    <flux:table.column>{{ __('Active') }}</flux:table.column>
                    <flux:table.column>{{ __('Options Count') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->questions as $question)
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
                            <flux:table.cell>{{ $question->options->count() }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button size="sm" variant="ghost" icon="list-bullet" wire:click="manageOptions({{ $question->id }})" class="me-2">
                                    {{ __('Options') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editQuestion({{ $question->id }})" class="me-2" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteQuestion({{ $question->id }})" wire:confirm="{{ __('Are you sure you want to delete this question?') }}" />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No questions found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showQuestionModal" class="w-full max-w-lg">
        <flux:heading level="2">
            {{ $editingQuestionId ? __('Edit Question') : __('Add Question') }}
        </flux:heading>

        <form wire:submit="saveQuestion" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Question') }}</flux:label>
                <flux:textarea wire:model="questionText" rows="3" required />
                <flux:error name="questionText" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Sort Order') }}</flux:label>
                <flux:input wire:model="questionSortOrder" type="number" min="0" step="1" required />
                <flux:error name="questionSortOrder" />
            </flux:field>

            <flux:field>
                <flux:switch wire:model="questionIsActive" :label="__('Active')" />
                <flux:error name="questionIsActive" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeQuestionModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingQuestionId ? __('Update') : __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showOptionsModal" class="w-full max-w-2xl">
        @if ($this->managingQuestion)
            <flux:heading level="2">{{ __('Options for :question', ['question' => Str::limit($this->managingQuestion->question_text, 50)]) }}</flux:heading>

            <div class="mt-6 space-y-6">
                <form wire:submit="saveOption" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading level="3">{{ $editingOptionId ? __('Edit Option') : __('Add Option') }}</flux:heading>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Option Text') }}</flux:label>
                            <flux:input wire:model="optionText" type="text" required />
                            <flux:error name="optionText" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Sort Order') }}</flux:label>
                            <flux:input wire:model="optionSortOrder" type="number" min="0" step="1" required />
                            <flux:error name="optionSortOrder" />
                        </flux:field>

                        <flux:field>
                            <flux:switch wire:model="optionIsNo" :label="__('Is No answer')" />
                            <flux:error name="optionIsNo" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:switch wire:model="optionIsActive" :label="__('Active')" />
                        <flux:error name="optionIsActive" />
                    </flux:field>

                    <div class="flex justify-end gap-3">
                        @if ($editingOptionId)
                            <flux:button type="button" variant="ghost" wire:click="createOption">
                                {{ __('Cancel Edit') }}
                            </flux:button>
                        @endif
                        <flux:button type="submit" variant="primary">
                            {{ $editingOptionId ? __('Update') : __('Add') }}
                        </flux:button>
                    </div>
                </form>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Option') }}</flux:table.column>
                        <flux:table.column>{{ __('Is No') }}</flux:table.column>
                        <flux:table.column>{{ __('Sort Order') }}</flux:table.column>
                        <flux:table.column>{{ __('Active') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->managingQuestion->options as $option)
                            <flux:table.row wire:key="option-{{ $option->id }}">
                                <flux:table.cell>{{ $option->option_text }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($option->is_no)
                                        <flux:badge size="sm" color="red">{{ __('Yes') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $option->sort_order }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($option->is_active)
                                        <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editOption({{ $option->id }})" class="me-2" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteOption({{ $option->id }})" wire:confirm="{{ __('Are you sure you want to delete this option?') }}" />
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                    {{ __('No options found.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </flux:modal>
</div>
