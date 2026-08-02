<?php

use App\Models\Doctor;
use App\Models\Employee;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Staff Profiles')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $designation = '';

    public string $department = '';

    public ?string $joiningDate = null;

    public string $employmentType = 'full_time';

    public string $status = 'active';

    public string $notes = '';

    public ?int $linkedUserId = null;

    public ?int $linkedDoctorId = null;

    /**
     * Restrict the page to admin users.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }

    /**
     * Get the paginated employees based on filters.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, Employee>
     */
    #[Computed]
    public function employees()
    {
        return Employee::with(['user', 'doctor'])
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhere('designation', 'like', "%{$this->search}%")
                        ->orWhere('department', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter !== 'all', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * Get users available to link to an employee profile.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function availableUsers(): Collection
    {
        return User::orderBy('name')->get();
    }

    /**
     * Get doctor profiles available to link to an employee profile.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function availableDoctors(): Collection
    {
        return Doctor::orderBy('name')->get();
    }

    /**
     * Open the modal to add a new employee.
     */
    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    /**
     * Open the modal to edit an existing employee.
     */
    public function editEmployee(int $id): void
    {
        $employee = Employee::findOrFail($id);

        $this->editingId = $employee->id;
        $this->name = $employee->name;
        $this->email = $employee->email ?? '';
        $this->phone = $employee->phone ?? '';
        $this->designation = $employee->designation ?? '';
        $this->department = $employee->department ?? '';
        $this->joiningDate = $employee->joining_date?->format('Y-m-d');
        $this->employmentType = $employee->employment_type;
        $this->status = $employee->status;
        $this->notes = $employee->notes ?? '';
        $this->linkedUserId = $employee->user_id;
        $this->linkedDoctorId = $employee->doctor_id;
        $this->resetValidation();
        $this->showModal = true;
    }

    /**
     * Save the employee profile.
     */
    public function saveEmployee(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:50'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'joiningDate' => ['nullable', 'date'],
            'employmentType' => ['required', Rule::in(['full_time', 'part_time', 'intern', 'consultant'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'linkedUserId' => ['nullable', 'integer', 'exists:users,id'],
            'linkedDoctorId' => ['nullable', 'integer', 'exists:doctors,id'],
        ];

        $validated = $this->validate($rules);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'designation' => $validated['designation'] ?: null,
            'department' => $validated['department'] ?: null,
            'joining_date' => $validated['joiningDate'] ?: null,
            'employment_type' => $validated['employmentType'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?: null,
            'user_id' => $validated['linkedUserId'],
            'doctor_id' => $validated['linkedDoctorId'],
        ];

        if ($this->editingId === null) {
            $data['created_by'] = auth()->id();

            $employee = Employee::create($data);

            $this->closeModal();

            Flux::toast(variant: 'success', text: __('Staff profile created successfully.'));

            $this->redirect(route('admin.employees.profile', $employee), navigate: true);

            return;
        }

        $employee = Employee::findOrFail($this->editingId);
        $employee->update($data);

        Flux::toast(variant: 'success', text: __('Staff profile updated successfully.'));

        $this->closeModal();
    }

    /**
     * Close the modal and reset the form.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Reset the form fields.
     */
    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->designation = '';
        $this->department = '';
        $this->joiningDate = null;
        $this->employmentType = 'full_time';
        $this->status = 'active';
        $this->notes = '';
        $this->linkedUserId = null;
        $this->linkedDoctorId = null;
        $this->resetValidation();
    }

    /**
     * Toggle the employee status between active and inactive.
     */
    public function toggleStatus(int $id): void
    {
        $employee = Employee::findOrFail($id);
        $employee->update(['status' => $employee->status === 'active' ? 'inactive' : 'active']);

        Flux::toast(variant: 'success', text: __('Status updated for :name.', ['name' => $employee->name]));
    }

    /**
     * Get the translated label for an employment type.
     */
    public function employmentTypeLabel(string $type): string
    {
        return match ($type) {
            'full_time' => __('Full-time'),
            'part_time' => __('Part-time'),
            'intern' => __('Intern'),
            'consultant' => __('Consultant'),
            default => $type,
        };
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Staff Profiles') }}</flux:heading>

            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                {{ __('Add Staff') }}
            </flux:button>
        </div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by name, email, designation...') }}"
                icon="magnifying-glass"
                class="w-full sm:max-w-md"
            />

            <flux:select wire:model.live="statusFilter" class="w-full sm:w-48">
                <flux:select.option value="all">{{ __('All statuses') }}</flux:select.option>
                <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            </flux:select>
        </div>

        @if ($this->employees->isEmpty())
            <flux:card>
                <div class="py-8 text-center text-zinc-500">
                    {{ __('No staff profiles found.') }}
                </div>
            </flux:card>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->employees as $employee)
                    <flux:card wire:key="employee-{{ $employee->id }}" class="flex flex-col gap-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                @if ($employee->hasPhoto())
                                    <flux:avatar size="md" :src="$employee->photoUrl()" :name="$employee->name" :initials="$employee->initials()" class="shrink-0" />
                                @else
                                    <flux:avatar size="md" :name="$employee->name" :initials="$employee->initials()" class="shrink-0" />
                                @endif

                                <div class="min-w-0">
                                    <flux:text class="text-xs text-zinc-500">{{ __('Employee ID') }} #{{ $employee->id }}</flux:text>
                                    <flux:heading level="3" class="mt-1 truncate text-lg">{{ $employee->name }}</flux:heading>
                                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ $employee->designation ?: __('No post held') }}
                                        @if ($employee->department)
                                            &middot; {{ $employee->department }}
                                        @endif
                                    </flux:text>
                                </div>
                            </div>

                            <flux:badge size="sm" color="{{ $employee->status === 'active' ? 'green' : 'zinc' }}">
                                {{ ucfirst($employee->status) }}
                            </flux:badge>
                        </div>

                        <div class="space-y-1 text-sm">
                            <flux:text>
                                <span class="text-zinc-500">{{ __('Type') }}:</span>
                                {{ $this->employmentTypeLabel($employee->employment_type) }}
                            </flux:text>
                            @if ($employee->phone)
                                <flux:text>
                                    <span class="text-zinc-500">{{ __('Contact') }}:</span>
                                    {{ $employee->phone }}
                                </flux:text>
                            @endif
                            @if ($employee->email)
                                <flux:text class="truncate">
                                    <span class="text-zinc-500">{{ __('Email') }}:</span>
                                    {{ $employee->email }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="mt-auto flex flex-wrap gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <flux:button size="sm" variant="primary" icon="eye" :href="route('admin.employees.profile', $employee)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editEmployee({{ $employee->id }})">
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="toggleStatus({{ $employee->id }})" wire:confirm="{{ __('Are you sure you want to change the status?') }}">
                                {{ $employee->status === 'active' ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <div class="mt-2">
                {{ $this->employees->links() }}
            </div>
        @endif
    </div>

    <flux:modal wire:model="showModal" class="w-full max-w-2xl">
        <flux:heading level="2">
            {{ $editingId === null ? __('Add Staff') : __('Edit Staff') }}
        </flux:heading>

        <form wire:submit="saveEmployee" class="mt-6 space-y-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="name" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Email') }}</flux:label>
                    <flux:input type="email" wire:model="email" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Contact Number') }}</flux:label>
                    <flux:input wire:model="phone" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Joining Date') }}</flux:label>
                    <flux:input type="date" wire:model="joiningDate" />
                    <flux:error name="joiningDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Post Held') }}</flux:label>
                    <flux:input wire:model="designation" placeholder="{{ __('e.g. Nurse, Admin Officer') }}" />
                    <flux:error name="designation" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Department') }}</flux:label>
                    <flux:input wire:model="department" placeholder="{{ __('e.g. Administration, Nursing') }}" />
                    <flux:error name="department" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Employment Type') }}</flux:label>
                    <flux:select wire:model="employmentType" required>
                        <flux:select.option value="full_time">{{ __('Full-time') }}</flux:select.option>
                        <flux:select.option value="part_time">{{ __('Part-time') }}</flux:select.option>
                        <flux:select.option value="intern">{{ __('Intern') }}</flux:select.option>
                        <flux:select.option value="consultant">{{ __('Consultant') }}</flux:select.option>
                    </flux:select>
                    <flux:error name="employmentType" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Status') }}</flux:label>
                    <flux:select wire:model="status" required>
                        <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                        <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
                    </flux:select>
                    <flux:error name="status" />
                </flux:field>

                <flux:field class="sm:col-span-2">
                    <flux:label>{{ __('Linked User Account') }}</flux:label>
                    <flux:select wire:model="linkedUserId">
                        <option value="">{{ __('No linked user') }}</option>
                        @foreach ($this->availableUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="linkedUserId" />
                </flux:field>

                <flux:field class="sm:col-span-2">
                    <flux:label>{{ __('Linked Doctor Profile') }}</flux:label>
                    <flux:select wire:model="linkedDoctorId">
                        <option value="">{{ __('No linked doctor') }}</option>
                        @foreach ($this->availableDoctors as $doctor)
                            <option value="{{ $doctor->id }}">{{ $doctor->name }} — {{ $doctor->specialization }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="linkedDoctorId" />
                </flux:field>

                <flux:field class="sm:col-span-2">
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="3" />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
