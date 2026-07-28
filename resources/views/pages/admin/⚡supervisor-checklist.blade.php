<?php

use App\Enums\UserRole;
use App\Models\SupervisorChecklistEntry;
use App\Models\User;
use App\Services\SupervisorChecklistService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supervisor Checklist Summary')] class extends Component
{
    public ?int $selectedSupervisorId = null;

    public string $selectedDate = '';

    public ?string $expandedBlock = null;

    public function mount(): void
    {
        $this->selectedDate = now()->format('Y-m-d');
    }

    /**
     * Get all supervisor users.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function supervisors(): Collection
    {
        return User::where('role', UserRole::Supervisor)->orderBy('name')->get();
    }

    /**
     * Get the selected supervisor.
     */
    #[Computed]
    public function supervisor(): ?User
    {
        if ($this->selectedSupervisorId === null) {
            return null;
        }

        return User::where('role', UserRole::Supervisor)->find($this->selectedSupervisorId);
    }

    /**
     * Get the blocks for the selected date.
     *
     * @return \Illuminate\Support\Collection<int, object{start: Carbon, end: Carbon}>
     */
    #[Computed]
    public function blocks(): \Illuminate\Support\Collection
    {
        return app(SupervisorChecklistService::class)->blocksForDate(Carbon::parse($this->selectedDate));
    }

    /**
     * Get entries for the selected supervisor and date.
     *
     * @return Collection<int, SupervisorChecklistEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        if ($this->supervisor === null) {
            return new Collection();
        }

        return SupervisorChecklistEntry::with(['responses.question', 'responses.options'])
            ->where('user_id', $this->supervisor->id)
            ->whereDate('block_starts_at', $this->selectedDate)
            ->orderBy('block_starts_at')
            ->get();
    }

    /**
     * Find the entry for a given block.
     */
    public function entryForBlock(object $block): ?SupervisorChecklistEntry
    {
        return $this->entries->first(
            fn (SupervisorChecklistEntry $entry) => $entry->block_starts_at->equalTo($block->start)
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
            <flux:heading level="1">{{ __('Supervisor Checklist Summary') }}</flux:heading>
        </div>

        <flux:card>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Supervisor') }}</flux:label>
                    <flux:select wire:model.live="selectedSupervisorId">
                        <option value="">{{ __('Select a supervisor') }}</option>
                        @foreach ($this->supervisors as $supervisor)
                            <option value="{{ $supervisor->id }}">{{ $supervisor->name }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input type="date" wire:model.live="selectedDate" />
                </flux:field>
            </div>
        </flux:card>

        @if ($this->supervisor)
            <flux:card>
                <flux:heading level="2" class="mb-4">
                    {{ __(':name — :date', ['name' => $this->supervisor->name, 'date' => Carbon::parse($this->selectedDate)->format('M j, Y')]) }}
                </flux:heading>

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
                                $blockKey = $block->start->format('H:i');
                            @endphp
                            <flux:table.row wire:key="block-{{ $blockKey }}">
                                <flux:table.cell>
                                    {{ $block->start->format('H:i') }} - {{ $block->end->format('H:i') }}
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
                                                            <th class="py-2 pe-4 text-start font-medium">{{ __('Selected Options') }}</th>
                                                            <th class="py-2 text-start font-medium">{{ __('Remarks') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($entry->responses as $response)
                                                            @php
                                                                $isNoResponse = $response->options->contains('is_no', true);
                                                            @endphp
                                                            <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ $isNoResponse ? 'bg-red-50 text-red-900 dark:bg-red-950/30 dark:text-red-100' : '' }}" wire:key="response-{{ $response->id }}">
                                                                <td class="py-2 pe-4 align-top">{{ $response->question->question_text }}</td>
                                                                <td class="py-2 pe-4 align-top">
                                                                    @if ($response->options->isEmpty())
                                                                        <span class="text-zinc-400">-</span>
                                                                    @else
                                                                        <div class="flex flex-wrap gap-1">
                                                                            @foreach ($response->options as $option)
                                                                                <flux:badge size="sm" color="{{ $option->is_no ? 'red' : 'blue' }}">{{ $option->option_text }}</flux:badge>
                                                                            @endforeach
                                                                        </div>
                                                                    @endif
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
                <flux:text class="text-center text-zinc-500">{{ __('Select a supervisor to view the daily summary.') }}</flux:text>
            </flux:card>
        @endif
    </div>
</div>
