<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('SQL Runner')] class extends Component
{
    public string $sql = '';

    public bool $requiresConfirmation = false;

    public ?string $detectedCommand = null;

    public ?array $results = null;

    public ?array $columns = null;

    public ?string $message = null;

    public ?string $error = null;

    public int $rowsAffected = 0;

    public int $resultCount = 0;

    /**
     * Validate the SQL input.
     */
    protected function rules(): array
    {
        return [
            'sql' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * Reset the result state before a new execution.
     */
    protected function resetResults(): void
    {
        $this->results = null;
        $this->columns = null;
        $this->message = null;
        $this->error = null;
        $this->rowsAffected = 0;
        $this->resultCount = 0;
    }

    /**
     * Determine the first SQL command keyword from the query.
     */
    protected function detectCommand(string $sql): ?string
    {
        if (preg_match('/^\s*([a-zA-Z]+)/', $sql, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Determine whether the query is a read-only SELECT statement.
     */
    protected function isSelectQuery(string $sql): bool
    {
        return preg_match('/^\s*select\b/i', $sql) === 1;
    }

    /**
     * Run the SQL query. Non-SELECT queries require confirmation first.
     */
    public function run(): void
    {
        $this->validate();
        $this->resetResults();
        $this->requiresConfirmation = false;

        $sql = trim($this->sql);

        if ($this->isSelectQuery($sql)) {
            $this->executeSelect($sql);

            return;
        }

        $this->detectedCommand = $this->detectCommand($sql);
        $this->requiresConfirmation = true;
        $this->message = __('This :command query may modify the database. Please confirm before running.', ['command' => $this->detectedCommand ?? 'SQL']);
    }

    /**
     * Execute a SELECT query and store the results.
     */
    protected function executeSelect(string $sql): void
    {
        try {
            $rows = DB::select($sql);

            $this->resultCount = count($rows);
            $this->results = array_slice($rows, 0, 1000);

            if ($this->results !== []) {
                $this->columns = array_keys((array) $this->results[0]);
            }

            $this->message = __('Query executed successfully. :count rows returned.', ['count' => $this->resultCount]);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Confirm and execute a non-SELECT query.
     */
    public function confirmRun(): void
    {
        $sql = trim($this->sql);
        $command = $this->detectCommand($sql);

        try {
            if (in_array($command, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                $this->rowsAffected = DB::affectingStatement($sql);
            } else {
                DB::statement($sql);
            }

            $this->message = __(':command query executed successfully.', ['command' => $command ?? 'SQL']);

            if ($this->rowsAffected > 0) {
                $this->message .= ' '.__(':count rows affected.', ['count' => $this->rowsAffected]);
            }
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->requiresConfirmation = false;
        $this->detectedCommand = null;
    }

    /**
     * Cancel the pending non-SELECT query.
     */
    public function cancelRun(): void
    {
        $this->requiresConfirmation = false;
        $this->detectedCommand = null;
        $this->message = __('Query cancelled.');
    }

    /**
     * Clear the SQL input and all results.
     */
    public function clear(): void
    {
        $this->sql = '';
        $this->resetResults();
        $this->requiresConfirmation = false;
        $this->detectedCommand = null;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('SQL Runner') }}</flux:heading>
        </div>

        <flux:card>
            <div class="space-y-4">
                <div>
                    <flux:heading level="2">{{ __('Query') }}</flux:heading>
                    <flux:text class="text-zinc-500">
                        {{ __('Run SQL against the default database. SELECT queries execute immediately; other commands require confirmation.') }}
                    </flux:text>
                </div>

                <flux:textarea
                    wire:model="sql"
                    rows="8"
                    placeholder="SELECT * FROM users LIMIT 10"
                    :disabled="$requiresConfirmation"
                    class="font-mono"
                />

                <div class="flex flex-wrap gap-3">
                    <flux:button
                        type="button"
                        variant="primary"
                        wire:click="run"
                        :disabled="$requiresConfirmation"
                    >
                        {{ __('Run SQL') }}
                    </flux:button>

                    <flux:button
                        type="button"
                        variant="ghost"
                        wire:click="clear"
                    >
                        {{ __('Clear') }}
                    </flux:button>
                </div>

                @if ($message)
                    <flux:callout variant="warning" class="mt-4" :dismissible="false">
                        <p>{{ $message }}</p>
                    </flux:callout>
                @endif

                @if ($error)
                    <flux:callout variant="danger" class="mt-4" :dismissible="false">
                        <p>{{ $error }}</p>
                    </flux:callout>
                @endif
            </div>
        </flux:card>

        @if ($results !== null)
            <flux:card>
                <flux:heading level="2">{{ __('Results') }}</flux:heading>

                <flux:text class="mb-4 text-zinc-500">
                    {{ __(':count rows returned', ['count' => $resultCount]) }}
                </flux:text>

                @if ($results === [])
                    <flux:text class="text-zinc-500">{{ __('No rows returned.') }}</flux:text>
                @else
                    <div class="overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                @foreach ($columns as $column)
                                    <flux:table.column>{{ $column }}</flux:table.column>
                                @endforeach
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($results as $row)
                                    <flux:table.row wire:key="sql-result-{{ $loop->index }}">
                                        @foreach ((array) $row as $value)
                                            <flux:table.cell>
                                                @if (is_null($value))
                                                    <span class="text-zinc-400">NULL</span>
                                                @else
                                                    {{ $value }}
                                                @endif
                                            </flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif
            </flux:card>
        @endif
    </div>

    <flux:modal wire:model="requiresConfirmation" class="w-full max-w-lg">
        <div class="space-y-4">
            <flux:heading level="2">{{ __('Confirm destructive query') }}</flux:heading>

            <flux:text>
                {{ __('You are about to run a :command query against the database. This operation cannot be undone.', ['command' => $detectedCommand ?? 'SQL']) }}
            </flux:text>

            <div class="rounded-lg bg-zinc-100 p-3 font-mono text-sm dark:bg-zinc-900">
                {{ $sql }}
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="cancelRun">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button type="button" variant="danger" wire:click="confirmRun">
                    {{ __('Run anyway') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
