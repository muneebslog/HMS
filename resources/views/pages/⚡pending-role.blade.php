<?php

use App\Enums\RoleRequestStatus;
use App\Enums\UserRole;
use App\Models\RoleRequest;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::pending')] #[Title('Pending Role Assignment')] class extends Component
{
    public string $requestedRole = '';

    public string $message = '';

    public bool $showRequestModal = false;

    /**
     * Get the user's most recent pending role request, if any.
     */
    #[Computed]
    public function pendingRequest(): ?RoleRequest
    {
        return auth()->user()
            ?->roleRequests()
            ->where('status', RoleRequestStatus::Pending)
            ->latest()
            ->first();
    }

    /**
     * Get the assignable roles a user may request.
     *
     * @return list<UserRole>
     */
    #[Computed]
    public function requestableRoles(): array
    {
        return [
            UserRole::Receptionist,
            UserRole::Management,
        ];
    }

    /**
     * Open the role request modal.
     */
    public function requestRole(): void
    {
        if ($this->pendingRequest !== null) {
            Flux::toast(variant: 'warning', text: __('You already have a pending role request.'));

            return;
        }

        $this->requestedRole = '';
        $this->message = '';
        $this->resetValidation();
        $this->showRequestModal = true;
    }

    /**
     * Close the role request modal.
     */
    public function closeRequestModal(): void
    {
        $this->showRequestModal = false;
        $this->requestedRole = '';
        $this->message = '';
        $this->resetValidation();
    }

    /**
     * Submit a new role request.
     */
    public function submitRequest(): void
    {
        if ($this->pendingRequest !== null) {
            Flux::toast(variant: 'warning', text: __('You already have a pending role request.'));

            return;
        }

        $validated = $this->validate([
            'requestedRole' => [
                'required',
                'string',
                Rule::in(array_map(fn (UserRole $role) => $role->value, $this->requestableRoles)),
            ],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $role = UserRole::from($validated['requestedRole']);

        RoleRequest::create([
            'user_id' => auth()->id(),
            'requested_role' => $role,
            'status' => RoleRequestStatus::Pending,
            'message' => $validated['message'] ?: null,
        ]);

        $this->closeRequestModal();

        Flux::toast(variant: 'success', text: __('Role request submitted. An admin will review it soon.'));
    }
}; ?>

<div class="flex min-h-screen items-center justify-center p-6">
    <flux:card class="w-full max-w-md text-center">
        <flux:heading level="1" class="text-2xl">{{ __('Account Pending') }}</flux:heading>

        <flux:text class="mt-4 text-zinc-500">
            {{ __('Your account does not have an assigned role yet. You cannot access the application until an administrator assigns you a role.') }}
        </flux:text>

        @if ($this->pendingRequest)
            <flux:badge size="lg" color="amber" class="mt-6">
                {{ __('Pending request for :role', ['role' => $this->pendingRequest->requested_role->label()]) }}
            </flux:badge>

            @if ($this->pendingRequest->message)
                <flux:text class="mt-4 text-sm text-zinc-500">
                    {{ $this->pendingRequest->message }}
                </flux:text>
            @endif
        @else
            <flux:button variant="primary" class="mt-6" wire:click="requestRole">
                {{ __('Request Role') }}
            </flux:button>
        @endif

        <div class="mt-6">
            <form method="POST" action="{{ route('logout') }}" class="inline">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </flux:card>

    <flux:modal wire:model="showRequestModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Request Role') }}</flux:heading>

        <form wire:submit="submitRequest" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Requested Role') }}</flux:label>
                <flux:select wire:model="requestedRole" required>
                    <option value="">{{ __('Select a role') }}</option>
                    @foreach ($this->requestableRoles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="requestedRole" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Message (optional)') }}</flux:label>
                <flux:textarea wire:model="message" rows="4" />
                <flux:error name="message" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeRequestModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Submit Request') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
