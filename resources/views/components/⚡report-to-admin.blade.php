<?php

use App\Enums\AdminReportStatus;
use App\Models\AdminReport;
use App\Models\AdminReportMessage;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public int $limit = 5;

    public ?int $selectedReportId = null;

    public string $subject = '';

    public string $body = '';

    public string $replyBody = '';

    public bool $showCreateForm = false;

    /**
     * Restrict the component to admin and receptionist users.
     */
    public function mount(): void
    {
        $this->ensureAuthorized();
    }

    /**
     * Refresh report threads when a new message is broadcast.
     */
    #[On('echo-private:hms.reception,.report.posted')]
    public function refreshReports(): void
    {
        unset($this->reports, $this->selectedReport);
    }

    /**
     * Get the report threads ordered with open threads first.
     *
     * @return Collection<int, AdminReport>
     */
    #[Computed]
    public function reports(): Collection
    {
        $user = auth()->user();

        return AdminReport::query()
            ->visibleTo($user)
            ->with(['creator', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->withCount('messages')
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [AdminReportStatus::Open->value])
            ->orderByDesc('last_message_at')
            ->limit($this->limit)
            ->get();
    }

    /**
     * Get the currently selected report with its messages.
     */
    #[Computed]
    public function selectedReport(): ?AdminReport
    {
        if ($this->selectedReportId === null) {
            return null;
        }

        $user = auth()->user();

        return AdminReport::query()
            ->visibleTo($user)
            ->with(['creator', 'messages.user'])
            ->find($this->selectedReportId);
    }

    /**
     * Select a report thread to view.
     */
    public function selectReport(int $reportId): void
    {
        $this->ensureAuthorized();

        $report = $this->findVisibleReport($reportId);

        if ($report === null) {
            abort(403);
        }

        $this->selectedReportId = $reportId;
        $this->replyBody = '';
        $this->resetValidation();
        unset($this->selectedReport);
    }

    /**
     * Show the create-thread form.
     */
    public function openCreateForm(): void
    {
        $this->ensureAuthorized();

        $this->showCreateForm = true;
        $this->selectedReportId = null;
        $this->subject = '';
        $this->body = '';
        $this->resetValidation();
        unset($this->selectedReport);
    }

    /**
     * Hide the create-thread form.
     */
    public function closeCreateForm(): void
    {
        $this->showCreateForm = false;
        $this->subject = '';
        $this->body = '';
        $this->resetValidation();
    }

    /**
     * Start a new report thread with an initial message.
     */
    public function startReport(): void
    {
        $this->ensureAuthorized();

        $validated = $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = auth()->user();

        $report = DB::transaction(function () use ($validated, $user) {
            $report = AdminReport::create([
                'created_by' => $user->id,
                'subject' => $validated['subject'],
                'status' => AdminReportStatus::Open,
                'last_message_at' => now(),
            ]);

            $message = AdminReportMessage::create([
                'admin_report_id' => $report->id,
                'user_id' => $user->id,
                'body' => $validated['body'],
            ]);

            app(NotificationService::class)->notifyAdminReportCreated($report, $message, $user);

            return $report;
        });

        $this->showCreateForm = false;
        $this->subject = '';
        $this->body = '';
        $this->selectedReportId = $report->id;
        unset($this->reports, $this->selectedReport);
    }

    /**
     * Reply to the selected report thread.
     */
    public function reply(): void
    {
        $this->ensureAuthorized();

        $validated = $this->validate([
            'replyBody' => ['required', 'string', 'max:5000'],
        ]);

        $report = $this->findVisibleReport($this->selectedReportId);

        if ($report === null) {
            abort(403);
        }

        $user = auth()->user();

        DB::transaction(function () use ($validated, $report, $user) {
            $message = AdminReportMessage::create([
                'admin_report_id' => $report->id,
                'user_id' => $user->id,
                'body' => $validated['replyBody'],
            ]);

            $report->update([
                'last_message_at' => now(),
                'status' => AdminReportStatus::Open,
            ]);

            app(NotificationService::class)->notifyAdminReportReplied($report, $message, $user);
        });

        $this->replyBody = '';
        unset($this->reports, $this->selectedReport);
    }

    /**
     * Close the selected report thread.
     */
    public function closeReport(): void
    {
        $this->ensureAuthorized();

        $report = $this->findVisibleReport($this->selectedReportId);

        if ($report === null) {
            abort(403);
        }

        $report->update(['status' => AdminReportStatus::Closed]);
        unset($this->reports, $this->selectedReport);
    }

    /**
     * Ensure the current user may use this component.
     */
    private function ensureAuthorized(): void
    {
        $user = auth()->user();

        if ($user === null || (! $user->isAdmin() && ! $user->isReceptionist())) {
            abort(403);
        }
    }

    /**
     * Find a report that the current user is allowed to access.
     */
    private function findVisibleReport(?int $reportId): ?AdminReport
    {
        if ($reportId === null) {
            return null;
        }

        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return AdminReport::query()
            ->visibleTo($user)
            ->find($reportId);
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="2">{{ __('Report to Admin') }}</flux:heading>

        <flux:button size="sm" variant="primary" icon="plus" wire:click="openCreateForm">
            {{ __('New Report') }}
        </flux:button>
    </div>

    @if ($showCreateForm)
        <form wire:submit="startReport" class="space-y-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
            <flux:field>
                <flux:label>{{ __('Subject') }}</flux:label>
                <flux:input wire:model="subject" placeholder="{{ __('What needs attention?') }}" />
                <flux:error name="subject" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Message') }}</flux:label>
                <flux:textarea wire:model="body" placeholder="{{ __('Describe the issue...') }}" rows="3" />
                <flux:error name="body" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" wire:click="closeCreateForm">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">
                    {{ __('Send Report') }}
                </flux:button>
            </div>
        </form>
    @endif

    <div class="grid gap-4 lg:grid-cols-5">
        <div class="space-y-2 lg:col-span-2">
            @forelse ($this->reports as $report)
                <button
                    type="button"
                    wire:key="report-thread-{{ $report->id }}"
                    wire:click="selectReport({{ $report->id }})"
                    class="w-full rounded-lg border p-3 text-start transition {{ $selectedReportId === $report->id ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950/40' : 'border-zinc-200 bg-white hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-800/80' }}"
                >
                    <div class="flex items-start justify-between gap-2">
                        <flux:heading level="3" class="text-sm">{{ $report->subject }}</flux:heading>
                        <flux:badge size="sm" color="{{ $report->status === \App\Enums\AdminReportStatus::Open ? 'green' : 'zinc' }}">
                            {{ $report->status->label() }}
                        </flux:badge>
                    </div>

                    <flux:text class="mt-1 text-xs text-zinc-500">
                        {{ $report->creator->name }}
                        &middot;
                        {{ $report->last_message_at?->diffForHumans() ?? $report->created_at->diffForHumans() }}
                    </flux:text>
                </button>
            @empty
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
                    <flux:text class="text-zinc-500">{{ __('No reports yet.') }}</flux:text>
                </div>
            @endforelse
        </div>

        <div class="lg:col-span-3">
            @if ($this->selectedReport)
                @php($report = $this->selectedReport)

                <div class="flex h-full flex-col rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-start justify-between gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700">
                        <div>
                            <flux:heading level="3">{{ $report->subject }}</flux:heading>
                            <flux:text class="mt-1 text-xs text-zinc-500">
                                {{ __('Started by :name', ['name' => $report->creator->name]) }}
                                &middot;
                                {{ $report->created_at->diffForHumans() }}
                            </flux:text>
                        </div>

                        @if ($report->status === \App\Enums\AdminReportStatus::Open)
                            <flux:button size="sm" variant="ghost" wire:click="closeReport">
                                {{ __('Close') }}
                            </flux:button>
                        @endif
                    </div>

                    <div class="max-h-80 flex-1 space-y-3 overflow-y-auto p-4">
                        @foreach ($report->messages as $message)
                            <div wire:key="report-message-{{ $message->id }}" class="flex gap-3">
                                <flux:avatar size="sm" class="shrink-0">
                                    {{ $message->user->initials() }}
                                </flux:avatar>

                                <div class="flex-1 space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium">{{ $message->user->name }}</span>
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $message->created_at->diffForHumans() }}</span>
                                    </div>

                                    <flux:text class="whitespace-pre-wrap text-sm">{{ $message->body }}</flux:text>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <form wire:submit="reply" class="space-y-3 border-t border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:field>
                            <flux:label>{{ __('Reply') }}</flux:label>
                            <flux:textarea wire:model="replyBody" placeholder="{{ __('Write your reply...') }}" rows="3" />
                            <flux:error name="replyBody" />
                        </flux:field>

                        <div class="flex justify-end">
                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">
                                {{ __('Send Reply') }}
                            </flux:button>
                        </div>
                    </form>
                </div>
            @else
                <div class="flex h-full items-center justify-center rounded-lg border border-dashed border-zinc-300 p-8 dark:border-zinc-600">
                    <flux:text class="text-zinc-500">{{ __('Select a report to view the conversation.') }}</flux:text>
                </div>
            @endif
        </div>
    </div>
</div>
