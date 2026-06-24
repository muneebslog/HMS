<?php

use App\Enums\RoleRequestStatus;
use App\Enums\UserRole;
use App\Models\RoleRequest;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users')] class extends Component
{
    public ?int $editingUserId = null;

    public string $editingRole = '';

    public ?int $processingRequestId = null;

    public string $adminNotes = '';

    public bool $showRequestModal = false;

    /**
     * Get all users ordered by name.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::orderBy('name')->get();
    }

    /**
     * Get pending role requests with their users.
     *
     * @return Collection<int, RoleRequest>
     */
    #[Computed]
    public function pendingRequests(): Collection
    {
        return RoleRequest::with('user')
            ->where('status', RoleRequestStatus::Pending)
            ->latest()
            ->get();
    }

    /**
     * Start editing the user's role.
     */
    public function editRole(int $userId): void
    {
        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $this->editingUserId = $user->id;
        $this->editingRole = $user->role->value;
    }

    /**
     * Cancel role editing.
     */
    public function cancelEdit(): void
    {
        $this->reset(['editingUserId', 'editingRole']);
    }

    /**
     * Save the updated role for the user.
     */
    public function saveRole(int $userId): void
    {
        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $validated = $this->validate([
            'editingRole' => ['required', 'string', Rule::in(UserRole::values())],
        ]);

        $newRole = UserRole::from($validated['editingRole']);

        if ($this->wouldLockOutAdmin($user, $newRole)) {
            Flux::toast(variant: 'danger', text: __('You cannot remove the last admin account.'));

            return;
        }

        $user->update(['role' => $newRole]);

        $this->reset(['editingUserId', 'editingRole']);

        Flux::toast(variant: 'success', text: __('Role updated for :name.', ['name' => $user->name]));
    }

    /**
     * Determine whether changing the user's role would remove the last admin.
     */
    private function wouldLockOutAdmin(User $user, UserRole $newRole): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        if ($newRole === UserRole::Admin) {
            return false;
        }

        if ($user->id === auth()->id()) {
            return true;
        }

        return User::where('role', UserRole::Admin)->count() <= 1;
    }

    /**
     * Open the modal to process a role request.
     */
    public function processRequest(int $requestId): void
    {
        $request = RoleRequest::find($requestId);

        if ($request === null || ! $request->isPending()) {
            return;
        }

        $this->processingRequestId = $request->id;
        $this->adminNotes = '';
        $this->resetValidation();
        $this->showRequestModal = true;
    }

    /**
     * Close the request processing modal.
     */
    public function closeRequestModal(): void
    {
        $this->showRequestModal = false;
        $this->processingRequestId = null;
        $this->adminNotes = '';
        $this->resetValidation();
    }

    /**
     * Approve the selected role request.
     */
    public function approveRequest(): void
    {
        $request = $this->currentRequest();

        if ($request === null) {
            return;
        }

        if ($this->wouldLockOutAdmin($request->user, $request->requested_role)) {
            Flux::toast(variant: 'danger', text: __('You cannot remove the last admin account.'));

            return;
        }

        $validated = $this->validate([
            'adminNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $request->update([
            'status' => RoleRequestStatus::Approved,
            'admin_notes' => $validated['adminNotes'] ?: null,
            'processed_by' => auth()->id(),
            'processed_at' => now(),
        ]);

        $request->user->update(['role' => $request->requested_role]);

        $this->closeRequestModal();

        Flux::toast(variant: 'success', text: __('Role request approved for :name.', ['name' => $request->user->name]));
    }

    /**
     * Reject the selected role request.
     */
    public function rejectRequest(): void
    {
        $request = $this->currentRequest();

        if ($request === null) {
            return;
        }

        $validated = $this->validate([
            'adminNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $request->update([
            'status' => RoleRequestStatus::Rejected,
            'admin_notes' => $validated['adminNotes'] ?: null,
            'processed_by' => auth()->id(),
            'processed_at' => now(),
        ]);

        $this->closeRequestModal();

        Flux::toast(variant: 'danger', text: __('Role request rejected for :name.', ['name' => $request->user->name]));
    }

    /**
     * Get the role request currently being processed.
     */
    #[Computed]
    public function currentRequest(): ?RoleRequest
    {
        if ($this->processingRequestId === null) {
            return null;
        }

        return RoleRequest::with('user')->find($this->processingRequestId);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Users') }}</flux:heading>
        </div>

        @if ($this->pendingRequests->isNotEmpty())
            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Pending Role Requests') }}</flux:heading>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                        <flux:table.column>{{ __('Email') }}</flux:table.column>
                        <flux:table.column>{{ __('Requested Role') }}</flux:table.column>
                        <flux:table.column>{{ __('Message') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->pendingRequests as $request)
                            <flux:table.row wire:key="request-{{ $request->id }}">
                                <flux:table.cell>{{ $request->user->name }}</flux:table.cell>
                                <flux:table.cell>{{ $request->user->email }}</flux:table.cell>
                                <flux:table.cell>{{ $request->requested_role->label() }}</flux:table.cell>
                                <flux:table.cell>{{ Str::limit($request->message, 50) ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $request->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button size="sm" variant="primary" icon="check" wire:click="processRequest({{ $request->id }})" class="me-2">
                                        {{ __('Review') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('All Users') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Email') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->users as $user)
                        <flux:table.row wire:key="user-{{ $user->id }}">
                            <flux:table.cell>{{ $user->name }}</flux:table.cell>
                            <flux:table.cell>{{ $user->email }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($editingUserId === $user->id)
                                    <flux:select wire:model="editingRole" size="sm" class="w-40">
                                        @foreach (App\Enums\UserRole::cases() as $role)
                                            <flux:select.option value="{{ $role->value }}">
                                                {{ $role->label() }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    {{ $user->roleLabel() }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                @if ($editingUserId === $user->id)
                                    <flux:button size="sm" variant="primary" wire:click="saveRole({{ $user->id }})" class="me-2">
                                        {{ __('Save') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="cancelEdit">
                                        {{ __('Cancel') }}
                                    </flux:button>
                                @else
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRole({{ $user->id }})">
                                        {{ __('Change Role') }}
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                {{ __('No users found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showRequestModal" class="w-full max-w-lg">
        @if ($this->currentRequest)
            <flux:heading level="2">{{ __('Review Role Request') }}</flux:heading>

            <div class="mt-4 space-y-3 text-sm">
                <div>
                    <flux:text class="text-zinc-500">{{ __('User') }}</flux:text>
                    <flux:text>{{ $this->currentRequest->user->name }} ({{ $this->currentRequest->user->email }})</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Requested Role') }}</flux:text>
                    <flux:text>{{ $this->currentRequest->requested_role->label() }}</flux:text>
                </div>
                @if ($this->currentRequest->message)
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Message') }}</flux:text>
                        <flux:text>{{ $this->currentRequest->message }}</flux:text>
                    </div>
                @endif
            </div>

            <form class="mt-6 space-y-6">
                <flux:field>
                    <flux:label>{{ __('Admin Notes (optional)') }}</flux:label>
                    <flux:textarea wire:model="adminNotes" rows="3" />
                    <flux:error name="adminNotes" />
                </flux:field>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="closeRequestModal">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="button" variant="danger" wire:click="rejectRequest" wire:confirm="{{ __('Are you sure you want to reject this request?') }}">
                        {{ __('Reject') }}
                    </flux:button>
                    <flux:button type="button" variant="primary" wire:click="approveRequest">
                        {{ __('Approve') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
