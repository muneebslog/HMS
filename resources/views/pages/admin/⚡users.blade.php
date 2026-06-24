<?php

use App\Enums\UserRole;
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

        $user->update(['role' => UserRole::from($validated['editingRole'])]);

        $this->reset(['editingUserId', 'editingRole']);

        Flux::toast(variant: 'success', text: __('Role updated for :name.', ['name' => $user->name]));
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Users') }}</flux:heading>
        </div>

        <flux:card>
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
</div>
