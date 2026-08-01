<?php

use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeExperience;
use App\Models\EmployeeQualification;
use App\Models\EmployeeTodo;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Staff Profile')] class extends Component
{
    use WithFileUploads;

    public Employee $employee;

    public bool $editingInfo = false;

    public string $name = '';

    public string $fatherName = '';

    public string $cnic = '';

    public ?string $dateOfBirth = null;

    public string $sex = '';

    public string $religionSect = '';

    public string $caste = '';

    public string $maritalStatus = '';

    public string $email = '';

    public string $phone = '';

    public string $currentAddress = '';

    public string $permanentAddress = '';

    public string $emergencyContact = '';

    public string $languages = '';

    public string $distanceTimeFromHospital = '';

    public string $designation = '';

    public string $department = '';

    public ?string $joiningDate = null;

    public string $employmentType = 'full_time';

    public string $status = 'active';

    public string $notes = '';

    public bool $undertakingAccepted = false;

    public ?int $linkedUserId = null;

    public ?int $linkedDoctorId = null;

    public bool $showDocumentModal = false;

    public string $documentTitle = '';

    public string $documentType = 'other';

    public $documentFile;

    public ?string $documentIssueDate = null;

    public ?string $documentExpiryDate = null;

    public string $documentNotes = '';

    public bool $showTodoModal = false;

    public ?int $editingTodoId = null;

    public string $todoTitle = '';

    public string $todoDescription = '';

    public ?string $todoDueDate = null;

    public bool $showQualificationModal = false;

    public ?int $editingQualificationId = null;

    public string $qualificationCourse = '';

    public ?string $qualificationPassingYear = null;

    public string $qualificationInstitution = '';

    public $qualificationDocument;

    public bool $showExperienceModal = false;

    public ?int $editingExperienceId = null;

    public string $experienceCompany = '';

    public ?string $experienceDateOfJoining = null;

    public ?string $experienceDateOfLeaving = null;

    public string $experienceReasonForLeaving = '';

    /**
     * Mount the component with the given employee.
     */
    public function mount(Employee $employee): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }

        $this->employee = $employee->load(['user', 'doctor']);
        $this->resetInfoForm();
    }

    /**
     * Get the documents for this employee.
     *
     * @return Collection<int, EmployeeDocument>
     */
    #[Computed]
    public function documents(): Collection
    {
        return $this->employee->documents()->with('creator')->get();
    }

    /**
     * Get the todos for this employee.
     *
     * @return Collection<int, EmployeeTodo>
     */
    #[Computed]
    public function todos(): Collection
    {
        return $this->employee->todos()->with('creator', 'completer')->get();
    }

    /**
     * Get pending todos.
     *
     * @return Collection<int, EmployeeTodo>
     */
    #[Computed]
    public function pendingTodos(): Collection
    {
        return $this->todos->whereNull('completed_at')->sortBy('due_date')->values();
    }

    /**
     * Get completed todos.
     *
     * @return Collection<int, EmployeeTodo>
     */
    #[Computed]
    public function completedTodos(): Collection
    {
        return $this->todos->whereNotNull('completed_at')->sortByDesc('completed_at')->values();
    }

    /**
     * Get qualifications for this employee.
     *
     * @return Collection<int, EmployeeQualification>
     */
    #[Computed]
    public function qualifications(): Collection
    {
        return $this->employee->qualifications()->get();
    }

    /**
     * Get experiences for this employee.
     *
     * @return Collection<int, EmployeeExperience>
     */
    #[Computed]
    public function experiences(): Collection
    {
        return $this->employee->experiences()->get();
    }

    /**
     * Get users available to link.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function availableUsers(): Collection
    {
        return User::orderBy('name')->get();
    }

    /**
     * Get doctors available to link.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function availableDoctors(): Collection
    {
        return Doctor::orderBy('name')->get();
    }

    /**
     * Reset the info form from the employee model.
     */
    public function resetInfoForm(): void
    {
        $this->name = $this->employee->name;
        $this->fatherName = $this->employee->father_name ?? '';
        $this->cnic = $this->employee->cnic ?? '';
        $this->dateOfBirth = $this->employee->date_of_birth?->format('Y-m-d');
        $this->sex = $this->employee->sex ?? '';
        $this->religionSect = $this->employee->religion_sect ?? '';
        $this->caste = $this->employee->caste ?? '';
        $this->maritalStatus = $this->employee->marital_status ?? '';
        $this->email = $this->employee->email ?? '';
        $this->phone = $this->employee->phone ?? '';
        $this->currentAddress = $this->employee->current_address ?? '';
        $this->permanentAddress = $this->employee->permanent_address ?? '';
        $this->emergencyContact = $this->employee->emergency_contact ?? '';
        $this->languages = $this->employee->languages ?? '';
        $this->distanceTimeFromHospital = $this->employee->distance_time_from_hospital ?? '';
        $this->designation = $this->employee->designation ?? '';
        $this->department = $this->employee->department ?? '';
        $this->joiningDate = $this->employee->joining_date?->format('Y-m-d');
        $this->employmentType = $this->employee->employment_type;
        $this->status = $this->employee->status;
        $this->notes = $this->employee->notes ?? '';
        $this->undertakingAccepted = (bool) $this->employee->undertaking_accepted;
        $this->linkedUserId = $this->employee->user_id;
        $this->linkedDoctorId = $this->employee->doctor_id;
        $this->resetValidation();
    }

    /**
     * Start editing the employee info.
     */
    public function startEditingInfo(): void
    {
        $this->editingInfo = true;
    }

    /**
     * Cancel editing the employee info.
     */
    public function cancelEditingInfo(): void
    {
        $this->editingInfo = false;
        $this->resetInfoForm();
    }

    /**
     * Save the employee info.
     */
    public function saveInfo(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'fatherName' => ['nullable', 'string', 'max:255'],
            'cnic' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'cnic')->ignore($this->employee->id)],
            'dateOfBirth' => ['nullable', 'date', 'before_or_equal:today'],
            'sex' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'religionSect' => ['nullable', 'string', 'max:255'],
            'caste' => ['nullable', 'string', 'max:255'],
            'maritalStatus' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($this->employee->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'currentAddress' => ['nullable', 'string', 'max:2000'],
            'permanentAddress' => ['nullable', 'string', 'max:2000'],
            'emergencyContact' => ['nullable', 'string', 'max:50'],
            'languages' => ['nullable', 'string', 'max:255'],
            'distanceTimeFromHospital' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'joiningDate' => ['nullable', 'date'],
            'employmentType' => ['required', Rule::in(['full_time', 'part_time', 'intern', 'consultant'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'undertakingAccepted' => ['accepted'],
            'linkedUserId' => ['nullable', 'integer', 'exists:users,id'],
            'linkedDoctorId' => ['nullable', 'integer', 'exists:doctors,id'],
        ]);

        $wasAccepted = $this->employee->undertaking_accepted;

        $this->employee->update([
            'name' => $validated['name'],
            'father_name' => $validated['fatherName'] ?: null,
            'cnic' => $validated['cnic'] ?: null,
            'date_of_birth' => $validated['dateOfBirth'] ?: null,
            'sex' => $validated['sex'] ?: null,
            'religion_sect' => $validated['religionSect'] ?: null,
            'caste' => $validated['caste'] ?: null,
            'marital_status' => $validated['maritalStatus'] ?: null,
            'email' => $validated['email'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'current_address' => $validated['currentAddress'] ?: null,
            'permanent_address' => $validated['permanentAddress'] ?: null,
            'emergency_contact' => $validated['emergencyContact'] ?: null,
            'languages' => $validated['languages'] ?: null,
            'distance_time_from_hospital' => $validated['distanceTimeFromHospital'] ?: null,
            'designation' => $validated['designation'] ?: null,
            'department' => $validated['department'] ?: null,
            'joining_date' => $validated['joiningDate'] ?: null,
            'employment_type' => $validated['employmentType'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?: null,
            'undertaking_accepted' => true,
            'undertaking_accepted_at' => $wasAccepted ? $this->employee->undertaking_accepted_at : now(),
            'user_id' => $validated['linkedUserId'],
            'doctor_id' => $validated['linkedDoctorId'],
        ]);

        $this->editingInfo = false;
        $this->employee->refresh()->load(['user', 'doctor']);
        $this->resetInfoForm();

        Flux::toast(variant: 'success', text: __('Profile updated successfully.'));
    }

    /**
     * Open the modal to add a document.
     */
    public function openDocumentModal(): void
    {
        $this->resetDocumentForm();
        $this->showDocumentModal = true;
    }

    /**
     * Close the document modal.
     */
    public function closeDocumentModal(): void
    {
        $this->showDocumentModal = false;
        $this->resetDocumentForm();
    }

    /**
     * Reset the document form.
     */
    public function resetDocumentForm(): void
    {
        $this->documentTitle = '';
        $this->documentType = 'other';
        $this->documentFile = null;
        $this->documentIssueDate = null;
        $this->documentExpiryDate = null;
        $this->documentNotes = '';
        $this->resetValidation();
    }

    /**
     * Save the uploaded document.
     */
    public function saveDocument(): void
    {
        $validated = $this->validate([
            'documentTitle' => ['required', 'string', 'max:255'],
            'documentType' => ['required', Rule::in(['degree', 'certificate', 'license', 'contract', 'other'])],
            'documentFile' => ['required', 'file', 'max:10240'],
            'documentIssueDate' => ['nullable', 'date'],
            'documentExpiryDate' => ['nullable', 'date', 'after_or_equal:documentIssueDate'],
            'documentNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file */
        $file = $this->documentFile;
        $path = $file->store("employee-documents/{$this->employee->id}", 'local');

        EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'title' => $validated['documentTitle'],
            'type' => $validated['documentType'],
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'notes' => $validated['documentNotes'] ?: null,
            'issue_date' => $validated['documentIssueDate'] ?: null,
            'expiry_date' => $validated['documentExpiryDate'] ?: null,
            'created_by' => auth()->id(),
        ]);

        $this->closeDocumentModal();

        Flux::toast(variant: 'success', text: __('Document uploaded successfully.'));
    }

    /**
     * Delete a document.
     */
    public function deleteDocument(int $documentId): void
    {
        $document = EmployeeDocument::where('employee_id', $this->employee->id)->findOrFail($documentId);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        Flux::toast(variant: 'success', text: __('Document deleted successfully.'));
    }

    /**
     * Open the modal to add a todo.
     */
    public function openTodoModal(): void
    {
        $this->resetTodoForm();
        $this->showTodoModal = true;
    }

    /**
     * Open the modal to edit a todo.
     */
    public function editTodo(int $todoId): void
    {
        $todo = EmployeeTodo::where('employee_id', $this->employee->id)->findOrFail($todoId);

        $this->editingTodoId = $todo->id;
        $this->todoTitle = $todo->title;
        $this->todoDescription = $todo->description ?? '';
        $this->todoDueDate = $todo->due_date->format('Y-m-d');
        $this->resetValidation();
        $this->showTodoModal = true;
    }

    /**
     * Close the todo modal.
     */
    public function closeTodoModal(): void
    {
        $this->showTodoModal = false;
        $this->resetTodoForm();
    }

    /**
     * Reset the todo form.
     */
    public function resetTodoForm(): void
    {
        $this->editingTodoId = null;
        $this->todoTitle = '';
        $this->todoDescription = '';
        $this->todoDueDate = null;
        $this->resetValidation();
    }

    /**
     * Save the todo.
     */
    public function saveTodo(): void
    {
        $validated = $this->validate([
            'todoTitle' => ['required', 'string', 'max:255'],
            'todoDescription' => ['nullable', 'string', 'max:2000'],
            'todoDueDate' => ['required', 'date'],
        ]);

        if ($this->editingTodoId === null) {
            $todo = EmployeeTodo::create([
                'employee_id' => $this->employee->id,
                'title' => $validated['todoTitle'],
                'description' => $validated['todoDescription'] ?: null,
                'due_date' => $validated['todoDueDate'],
                'created_by' => auth()->id(),
            ]);

            app(\App\Services\NotificationService::class)->notifyEmployeeTodoCreated($todo, auth()->user());

            Flux::toast(variant: 'success', text: __('Todo added successfully.'));
        } else {
            $todo = EmployeeTodo::where('employee_id', $this->employee->id)->findOrFail($this->editingTodoId);
            $todo->update([
                'title' => $validated['todoTitle'],
                'description' => $validated['todoDescription'] ?: null,
                'due_date' => $validated['todoDueDate'],
            ]);

            Flux::toast(variant: 'success', text: __('Todo updated successfully.'));
        }

        $this->closeTodoModal();
    }

    /**
     * Mark a todo as done.
     */
    public function markTodoDone(int $todoId): void
    {
        $todo = EmployeeTodo::where('employee_id', $this->employee->id)->findOrFail($todoId);
        $todo->markAsDone(auth()->user());

        Flux::toast(variant: 'success', text: __('Todo marked as done.'));
    }

    /**
     * Reopen a completed todo.
     */
    public function reopenTodo(int $todoId): void
    {
        $todo = EmployeeTodo::where('employee_id', $this->employee->id)->findOrFail($todoId);
        $todo->reopen();

        Flux::toast(variant: 'success', text: __('Todo reopened.'));
    }

    /**
     * Delete a todo.
     */
    public function deleteTodo(int $todoId): void
    {
        $todo = EmployeeTodo::where('employee_id', $this->employee->id)->findOrFail($todoId);
        $todo->delete();

        Flux::toast(variant: 'success', text: __('Todo deleted successfully.'));
    }

    /**
     * Open the modal to add a qualification.
     */
    public function openQualificationModal(): void
    {
        $this->resetQualificationForm();
        $this->showQualificationModal = true;
    }

    /**
     * Open the modal to edit a qualification.
     */
    public function editQualification(int $qualificationId): void
    {
        $qualification = EmployeeQualification::where('employee_id', $this->employee->id)->findOrFail($qualificationId);

        $this->editingQualificationId = $qualification->id;
        $this->qualificationCourse = $qualification->course;
        $this->qualificationPassingYear = $qualification->passing_year !== null ? (string) $qualification->passing_year : null;
        $this->qualificationInstitution = $qualification->institution ?? '';
        $this->qualificationDocument = null;
        $this->resetValidation();
        $this->showQualificationModal = true;
    }

    /**
     * Close the qualification modal.
     */
    public function closeQualificationModal(): void
    {
        $this->showQualificationModal = false;
        $this->resetQualificationForm();
    }

    /**
     * Reset the qualification form.
     */
    public function resetQualificationForm(): void
    {
        $this->editingQualificationId = null;
        $this->qualificationCourse = '';
        $this->qualificationPassingYear = null;
        $this->qualificationInstitution = '';
        $this->qualificationDocument = null;
        $this->resetValidation();
    }

    /**
     * Save the qualification.
     */
    public function saveQualification(): void
    {
        $rules = [
            'qualificationCourse' => ['required', 'string', 'max:255'],
            'qualificationPassingYear' => ['nullable', 'integer', 'min:1950', 'max:'.((int) date('Y') + 1)],
            'qualificationInstitution' => ['nullable', 'string', 'max:255'],
            'qualificationDocument' => ['nullable', 'file', 'max:10240'],
        ];

        $validated = $this->validate($rules);

        $data = [
            'course' => $validated['qualificationCourse'],
            'passing_year' => $validated['qualificationPassingYear'] ?: null,
            'institution' => $validated['qualificationInstitution'] ?: null,
        ];

        if ($this->qualificationDocument) {
            /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file */
            $file = $this->qualificationDocument;
            $data['document_path'] = $file->store("employee-qualifications/{$this->employee->id}", 'local');
            $data['original_name'] = $file->getClientOriginalName();
        }

        if ($this->editingQualificationId === null) {
            EmployeeQualification::create([
                ...$data,
                'employee_id' => $this->employee->id,
                'created_by' => auth()->id(),
            ]);

            Flux::toast(variant: 'success', text: __('Qualification added successfully.'));
        } else {
            $qualification = EmployeeQualification::where('employee_id', $this->employee->id)->findOrFail($this->editingQualificationId);

            if (isset($data['document_path']) && filled($qualification->document_path)) {
                Storage::disk('local')->delete($qualification->document_path);
            }

            $qualification->update($data);

            Flux::toast(variant: 'success', text: __('Qualification updated successfully.'));
        }

        $this->closeQualificationModal();
        unset($this->qualifications);
    }

    /**
     * Delete a qualification.
     */
    public function deleteQualification(int $qualificationId): void
    {
        $qualification = EmployeeQualification::where('employee_id', $this->employee->id)->findOrFail($qualificationId);

        if (filled($qualification->document_path)) {
            Storage::disk('local')->delete($qualification->document_path);
        }

        $qualification->delete();
        unset($this->qualifications);

        Flux::toast(variant: 'success', text: __('Qualification deleted successfully.'));
    }

    /**
     * Open the modal to add experience.
     */
    public function openExperienceModal(): void
    {
        $this->resetExperienceForm();
        $this->showExperienceModal = true;
    }

    /**
     * Open the modal to edit experience.
     */
    public function editExperience(int $experienceId): void
    {
        $experience = EmployeeExperience::where('employee_id', $this->employee->id)->findOrFail($experienceId);

        $this->editingExperienceId = $experience->id;
        $this->experienceCompany = $experience->company;
        $this->experienceDateOfJoining = $experience->date_of_joining->format('Y-m-d');
        $this->experienceDateOfLeaving = $experience->date_of_leaving?->format('Y-m-d');
        $this->experienceReasonForLeaving = $experience->reason_for_leaving ?? '';
        $this->resetValidation();
        $this->showExperienceModal = true;
    }

    /**
     * Close the experience modal.
     */
    public function closeExperienceModal(): void
    {
        $this->showExperienceModal = false;
        $this->resetExperienceForm();
    }

    /**
     * Reset the experience form.
     */
    public function resetExperienceForm(): void
    {
        $this->editingExperienceId = null;
        $this->experienceCompany = '';
        $this->experienceDateOfJoining = null;
        $this->experienceDateOfLeaving = null;
        $this->experienceReasonForLeaving = '';
        $this->resetValidation();
    }

    /**
     * Save the experience.
     */
    public function saveExperience(): void
    {
        $validated = $this->validate([
            'experienceCompany' => ['required', 'string', 'max:255'],
            'experienceDateOfJoining' => ['required', 'date'],
            'experienceDateOfLeaving' => ['nullable', 'date', 'after_or_equal:experienceDateOfJoining'],
            'experienceReasonForLeaving' => ['nullable', 'string', 'max:2000'],
        ]);

        $data = [
            'company' => $validated['experienceCompany'],
            'date_of_joining' => $validated['experienceDateOfJoining'],
            'date_of_leaving' => $validated['experienceDateOfLeaving'] ?: null,
            'reason_for_leaving' => $validated['experienceReasonForLeaving'] ?: null,
        ];

        if ($this->editingExperienceId === null) {
            EmployeeExperience::create([
                ...$data,
                'employee_id' => $this->employee->id,
            ]);

            Flux::toast(variant: 'success', text: __('Experience added successfully.'));
        } else {
            $experience = EmployeeExperience::where('employee_id', $this->employee->id)->findOrFail($this->editingExperienceId);
            $experience->update($data);

            Flux::toast(variant: 'success', text: __('Experience updated successfully.'));
        }

        $this->closeExperienceModal();
        unset($this->experiences);
    }

    /**
     * Delete an experience.
     */
    public function deleteExperience(int $experienceId): void
    {
        $experience = EmployeeExperience::where('employee_id', $this->employee->id)->findOrFail($experienceId);
        $experience->delete();
        unset($this->experiences);

        Flux::toast(variant: 'success', text: __('Experience deleted successfully.'));
    }

    /**
     * Get the label for a document type.
     */
    public function documentTypeLabel(string $type): string
    {
        return match ($type) {
            'degree' => __('Degree'),
            'certificate' => __('Certificate'),
            'license' => __('License'),
            'contract' => __('Contract'),
            default => __('Other'),
        };
    }

    /**
     * Get the label for an employment type.
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

    /**
     * Get the label for sex.
     */
    public function sexLabel(?string $sex): string
    {
        return match ($sex) {
            'male' => __('Male'),
            'female' => __('Female'),
            'other' => __('Other'),
            default => '-',
        };
    }

    /**
     * Get the label for marital status.
     */
    public function maritalStatusLabel(?string $status): string
    {
        return match ($status) {
            'single' => __('Single'),
            'married' => __('Married'),
            'divorced' => __('Divorced'),
            'widowed' => __('Widowed'),
            default => '-',
        };
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('admin.employees')" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <div>
                    <flux:text class="text-xs text-zinc-500">{{ __('Employee ID') }} #{{ $employee->id }}</flux:text>
                    <div class="flex items-center gap-3">
                        <flux:heading level="1">{{ $employee->name }}</flux:heading>
                        <flux:badge size="sm" color="{{ $employee->status === 'active' ? 'green' : 'zinc' }}">
                            {{ ucfirst($employee->status) }}
                        </flux:badge>
                    </div>
                </div>
            </div>
        </div>

        {{-- Profile Info --}}
        <flux:card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Personal & Contact Information') }}</flux:heading>

                @if (! $editingInfo)
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startEditingInfo">
                        {{ __('Edit') }}
                    </flux:button>
                @endif
            </div>

            @if ($editingInfo)
                <form wire:submit="saveInfo" class="mt-6 space-y-8">
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Employee ID') }}</flux:label>
                            <flux:input value="#{{ $employee->id }}" disabled />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Name') }}</flux:label>
                            <flux:input wire:model="name" required />
                            <flux:error name="name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Father Name') }}</flux:label>
                            <flux:input wire:model="fatherName" />
                            <flux:error name="fatherName" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('CNIC') }}</flux:label>
                            <flux:input wire:model="cnic" placeholder="{{ __('e.g. 35202-1234567-1') }}" />
                            <flux:error name="cnic" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Post Held') }}</flux:label>
                            <flux:input wire:model="designation" />
                            <flux:error name="designation" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Date of Birth') }}</flux:label>
                            <flux:input type="date" wire:model.live="dateOfBirth" />
                            <flux:error name="dateOfBirth" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Age') }}</flux:label>
                            <flux:input
                                value="{{ $dateOfBirth ? \Carbon\Carbon::parse($dateOfBirth)->age : '-' }}"
                                disabled
                            />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Sex') }}</flux:label>
                            <flux:select wire:model="sex">
                                <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                                <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                                <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                                <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="sex" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Religion / Sect') }}</flux:label>
                            <flux:input wire:model="religionSect" />
                            <flux:error name="religionSect" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Caste') }}</flux:label>
                            <flux:input wire:model="caste" />
                            <flux:error name="caste" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Marital Status') }}</flux:label>
                            <flux:select wire:model="maritalStatus">
                                <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                                <flux:select.option value="single">{{ __('Single') }}</flux:select.option>
                                <flux:select.option value="married">{{ __('Married') }}</flux:select.option>
                                <flux:select.option value="divorced">{{ __('Divorced') }}</flux:select.option>
                                <flux:select.option value="widowed">{{ __('Widowed') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="maritalStatus" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Email Address') }}</flux:label>
                            <flux:input type="email" wire:model="email" />
                            <flux:error name="email" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Contact Number') }}</flux:label>
                            <flux:input wire:model="phone" />
                            <flux:error name="phone" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Emergency Contact Number') }}</flux:label>
                            <flux:input wire:model="emergencyContact" />
                            <flux:error name="emergencyContact" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Languages') }}</flux:label>
                            <flux:input wire:model="languages" placeholder="{{ __('e.g. Urdu, English, Punjabi') }}" />
                            <flux:error name="languages" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Distance / Time from Hospital') }}</flux:label>
                            <flux:input wire:model="distanceTimeFromHospital" placeholder="{{ __('e.g. 12 km / 25 min') }}" />
                            <flux:error name="distanceTimeFromHospital" />
                        </flux:field>

                        <flux:field class="sm:col-span-2 lg:col-span-3">
                            <flux:label>{{ __('Current Address') }}</flux:label>
                            <flux:textarea wire:model="currentAddress" rows="2" />
                            <flux:error name="currentAddress" />
                        </flux:field>

                        <flux:field class="sm:col-span-2 lg:col-span-3">
                            <flux:label>{{ __('Permanent Address') }}</flux:label>
                            <flux:textarea wire:model="permanentAddress" rows="2" />
                            <flux:error name="permanentAddress" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Department') }}</flux:label>
                            <flux:input wire:model="department" />
                            <flux:error name="department" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Joining Date') }}</flux:label>
                            <flux:input type="date" wire:model="joiningDate" />
                            <flux:error name="joiningDate" />
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

                        <flux:field class="sm:col-span-2 lg:col-span-3">
                            <flux:label>{{ __('Linked User Account') }}</flux:label>
                            <flux:select wire:model="linkedUserId">
                                <option value="">{{ __('No linked user') }}</option>
                                @foreach ($this->availableUsers as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="linkedUserId" />
                        </flux:field>

                        <flux:field class="sm:col-span-2 lg:col-span-3">
                            <flux:label>{{ __('Linked Doctor Profile') }}</flux:label>
                            <flux:select wire:model="linkedDoctorId">
                                <option value="">{{ __('No linked doctor') }}</option>
                                @foreach ($this->availableDoctors as $doctor)
                                    <option value="{{ $doctor->id }}">{{ $doctor->name }} — {{ $doctor->specialization }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="linkedDoctorId" />
                        </flux:field>

                        <flux:field class="sm:col-span-2 lg:col-span-3">
                            <flux:label>{{ __('Notes') }}</flux:label>
                            <flux:textarea wire:model="notes" rows="3" />
                            <flux:error name="notes" />
                        </flux:field>
                    </div>

                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading level="3" class="mb-3 text-base">{{ __('Undertaking') }}</flux:heading>
                        <flux:checkbox
                            wire:model="undertakingAccepted"
                            label="{{ __('I :name s/o, d/o :father, do hereby undertake that all the above given info are correct to the best of my knowledge and belief and nothing concealed therein.', ['name' => $name ?: __('[Name]'), 'father' => $fatherName ?: __('[Father Name]')]) }}"
                        />
                        <flux:error name="undertakingAccepted" />
                    </div>

                    <div class="flex justify-end gap-3">
                        <flux:button type="button" variant="ghost" wire:click="cancelEditingInfo">
                            {{ __('Cancel') }}
                        </flux:button>
                        <flux:button type="submit" variant="primary">
                            {{ __('Save Changes') }}
                        </flux:button>
                    </div>
                </form>
            @else
                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Employee ID') }}</flux:text>
                        <flux:text>#{{ $employee->id }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Name') }}</flux:text>
                        <flux:text>{{ $employee->name }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Father Name') }}</flux:text>
                        <flux:text>{{ $employee->father_name ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('CNIC') }}</flux:text>
                        <flux:text>{{ $employee->cnic ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Post Held') }}</flux:text>
                        <flux:text>{{ $employee->designation ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Date of Birth') }}</flux:text>
                        <flux:text>{{ $employee->date_of_birth?->format('Y-m-d') ?? '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Age') }}</flux:text>
                        <flux:text>{{ $employee->age ?? '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Sex') }}</flux:text>
                        <flux:text>{{ $this->sexLabel($employee->sex) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Religion / Sect') }}</flux:text>
                        <flux:text>{{ $employee->religion_sect ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Caste') }}</flux:text>
                        <flux:text>{{ $employee->caste ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Marital Status') }}</flux:text>
                        <flux:text>{{ $this->maritalStatusLabel($employee->marital_status) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Email Address') }}</flux:text>
                        <flux:text>{{ $employee->email ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Contact Number') }}</flux:text>
                        <flux:text>{{ $employee->phone ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Emergency Contact Number') }}</flux:text>
                        <flux:text>{{ $employee->emergency_contact ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Languages') }}</flux:text>
                        <flux:text>{{ $employee->languages ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Distance / Time from Hospital') }}</flux:text>
                        <flux:text>{{ $employee->distance_time_from_hospital ?: '-' }}</flux:text>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <flux:text class="text-zinc-500">{{ __('Current Address') }}</flux:text>
                        <flux:text class="whitespace-pre-wrap">{{ $employee->current_address ?: '-' }}</flux:text>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <flux:text class="text-zinc-500">{{ __('Permanent Address') }}</flux:text>
                        <flux:text class="whitespace-pre-wrap">{{ $employee->permanent_address ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Department') }}</flux:text>
                        <flux:text>{{ $employee->department ?: '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Joining Date') }}</flux:text>
                        <flux:text>{{ $employee->joining_date?->format('Y-m-d') ?? '-' }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Employment Type') }}</flux:text>
                        <flux:text>{{ $this->employmentTypeLabel($employee->employment_type) }}</flux:text>
                    </div>
                    @if ($employee->user)
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Linked User') }}</flux:text>
                            <flux:text>{{ $employee->user->name }} ({{ $employee->user->email }})</flux:text>
                        </div>
                    @endif
                    @if ($employee->doctor)
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Linked Doctor') }}</flux:text>
                            <flux:text>{{ $employee->doctor->name }} — {{ $employee->doctor->specialization }}</flux:text>
                        </div>
                    @endif
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Undertaking') }}</flux:text>
                        <flux:text>
                            @if ($employee->undertaking_accepted)
                                {{ __('Accepted') }}
                                @if ($employee->undertaking_accepted_at)
                                    ({{ $employee->undertaking_accepted_at->format('Y-m-d') }})
                                @endif
                            @else
                                {{ __('Not accepted') }}
                            @endif
                        </flux:text>
                    </div>
                </div>

                @if ($employee->notes)
                    <div class="mt-6">
                        <flux:text class="text-zinc-500">{{ __('Notes') }}</flux:text>
                        <flux:text class="whitespace-pre-wrap">{{ $employee->notes }}</flux:text>
                    </div>
                @endif
            @endif
        </flux:card>

        {{-- Qualifications --}}
        <flux:card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Qualifications') }}</flux:heading>

                <flux:button size="sm" variant="primary" icon="plus" wire:click="openQualificationModal">
                    {{ __('Add Qualification') }}
                </flux:button>
            </div>

            <div class="mt-6 space-y-3">
                @forelse ($this->qualifications as $qualification)
                    <div wire:key="qualification-{{ $qualification->id }}" class="flex items-start gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="min-w-0 flex-1">
                            <flux:heading level="3" class="text-base">{{ $qualification->course }}</flux:heading>
                            <flux:text class="mt-1 text-sm text-zinc-500">
                                @if ($qualification->passing_year)
                                    {{ __('Passing Year') }}: {{ $qualification->passing_year }}
                                @endif
                                @if ($qualification->institution)
                                    &middot; {{ $qualification->institution }}
                                @endif
                            </flux:text>
                            @if ($qualification->hasDocument())
                                <flux:text class="mt-1 text-sm text-zinc-500">{{ $qualification->original_name }}</flux:text>
                            @endif
                        </div>

                        <div class="flex shrink-0 flex-col gap-2">
                            @if ($qualification->hasDocument())
                                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" href="{{ route('employee-qualifications.download', $qualification) }}" wire:navigate="false">
                                    {{ __('Download') }}
                                </flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editQualification({{ $qualification->id }})">
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteQualification({{ $qualification->id }})" wire:confirm="{{ __('Are you sure you want to delete this qualification?') }}">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500">{{ __('No qualifications added yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </flux:card>

        {{-- Experience --}}
        <flux:card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Experience') }}</flux:heading>

                <flux:button size="sm" variant="primary" icon="plus" wire:click="openExperienceModal">
                    {{ __('Add Experience') }}
                </flux:button>
            </div>

            <div class="mt-6 space-y-3">
                @forelse ($this->experiences as $experience)
                    <div wire:key="experience-{{ $experience->id }}" class="flex items-start gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="min-w-0 flex-1">
                            <flux:heading level="3" class="text-base">{{ $experience->company }}</flux:heading>
                            <flux:text class="mt-1 text-sm text-zinc-500">
                                {{ $experience->date_of_joining->format('Y-m-d') }}
                                —
                                {{ $experience->date_of_leaving?->format('Y-m-d') ?? __('Present') }}
                            </flux:text>
                            @if ($experience->reason_for_leaving)
                                <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ __('Reason for leaving') }}: {{ $experience->reason_for_leaving }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="flex shrink-0 flex-col gap-2">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editExperience({{ $experience->id }})">
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteExperience({{ $experience->id }})" wire:confirm="{{ __('Are you sure you want to delete this experience?') }}">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500">{{ __('No experience records added yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </flux:card>

        {{-- Documents --}}
        <flux:card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Documents') }}</flux:heading>

                <flux:button size="sm" variant="primary" icon="plus" wire:click="openDocumentModal">
                    {{ __('Add Document') }}
                </flux:button>
            </div>

            <div class="mt-6 space-y-3">
                @forelse ($this->documents as $document)
                    <div wire:key="document-{{ $document->id }}" class="flex items-start gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mt-1 shrink-0">
                            <flux:icon name="document-text" class="size-5 text-zinc-400" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading level="3" class="text-base">{{ $document->title }}</flux:heading>
                                <flux:badge size="sm" color="zinc">{{ $this->documentTypeLabel($document->type) }}</flux:badge>
                                @if ($document->isExpired())
                                    <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                                @elseif ($document->expiry_date && $document->expiry_date->diffInDays(now()) <= 30)
                                    <flux:badge size="sm" color="amber">{{ __('Expiring soon') }}</flux:badge>
                                @endif
                            </div>

                            <flux:text class="mt-1 text-sm text-zinc-500">
                                {{ $document->original_name }}
                                @if ($document->issue_date)
                                    &middot; {{ __('Issued') }} {{ $document->issue_date->format('Y-m-d') }}
                                @endif
                                @if ($document->expiry_date)
                                    &middot; {{ __('Expires') }} {{ $document->expiry_date->format('Y-m-d') }}
                                @endif
                            </flux:text>

                            @if ($document->notes)
                                <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $document->notes }}</flux:text>
                            @endif
                        </div>

                        <div class="flex shrink-0 flex-col gap-2">
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" href="{{ route('employee-documents.download', $document) }}" wire:navigate="false">
                                {{ __('Download') }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteDocument({{ $document->id }})" wire:confirm="{{ __('Are you sure you want to delete this document?') }}">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500">{{ __('No documents uploaded yet.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </flux:card>

        {{-- Todos --}}
        <flux:card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Todos & Reminders') }}</flux:heading>

                <flux:button size="sm" variant="primary" icon="plus" wire:click="openTodoModal">
                    {{ __('Add Todo') }}
                </flux:button>
            </div>

            <div class="mt-6 space-y-6">
                @if ($this->pendingTodos->isNotEmpty())
                    <div>
                        <flux:heading level="3" class="mb-3 text-sm font-medium text-zinc-500">{{ __('Pending') }}</flux:heading>
                        <div class="space-y-3">
                            @foreach ($this->pendingTodos as $todo)
                                <div wire:key="todo-pending-{{ $todo->id }}" class="flex items-start gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                    <div class="mt-1 shrink-0">
                                        <flux:icon name="clock" class="size-5 {{ $todo->isOverdue() ? 'text-red-500' : 'text-amber-500' }}" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:heading level="4" class="text-base">{{ $todo->title }}</flux:heading>
                                            @if ($todo->isOverdue())
                                                <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                                            @endif
                                        </div>

                                        @if ($todo->description)
                                            <flux:text class="mt-1 text-sm text-zinc-500">{{ $todo->description }}</flux:text>
                                        @endif

                                        <flux:text class="mt-2 text-xs text-zinc-400">
                                            {{ __('Due') }} {{ $todo->due_date->format('Y-m-d') }}
                                            &middot; {{ __('Added') }} {{ $todo->created_at->diffForHumans() }}
                                        </flux:text>
                                    </div>

                                    <div class="flex shrink-0 flex-col gap-2">
                                        <flux:button size="sm" variant="primary" icon="check" wire:click="markTodoDone({{ $todo->id }})">
                                            {{ __('Done') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editTodo({{ $todo->id }})">
                                            {{ __('Edit') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteTodo({{ $todo->id }})" wire:confirm="{{ __('Are you sure?') }}">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($this->completedTodos->isNotEmpty())
                    <div>
                        <flux:heading level="3" class="mb-3 text-sm font-medium text-zinc-500">{{ __('Completed') }}</flux:heading>
                        <div class="space-y-3">
                            @foreach ($this->completedTodos as $todo)
                                <div wire:key="todo-completed-{{ $todo->id }}" class="flex items-start gap-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 opacity-75 dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <div class="mt-1 shrink-0">
                                        <flux:icon name="check-circle" class="size-5 text-green-500" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <flux:heading level="4" class="text-base line-through">{{ $todo->title }}</flux:heading>

                                        @if ($todo->description)
                                            <flux:text class="mt-1 text-sm text-zinc-500">{{ $todo->description }}</flux:text>
                                        @endif

                                        <flux:text class="mt-2 text-xs text-zinc-400">
                                            {{ __('Due') }} {{ $todo->due_date->format('Y-m-d') }}
                                            &middot; {{ __('Completed') }} {{ $todo->completed_at?->diffForHumans() }}
                                            @if ($todo->completer)
                                                {{ __('by') }} {{ $todo->completer->name }}
                                            @endif
                                        </flux:text>
                                    </div>

                                    <div class="flex shrink-0 flex-col gap-2">
                                        <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="reopenTodo({{ $todo->id }})">
                                            {{ __('Reopen') }}
                                        </flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($this->pendingTodos->isEmpty() && $this->completedTodos->isEmpty())
                    <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                        <flux:text class="text-zinc-500">{{ __('No todos or reminders yet.') }}</flux:text>
                    </div>
                @endif
            </div>
        </flux:card>
    </div>

    {{-- Add Document Modal --}}
    <flux:modal wire:model="showDocumentModal" class="w-full max-w-lg">
        <flux:heading level="2">{{ __('Add Document') }}</flux:heading>

        <form wire:submit="saveDocument" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Title') }}</flux:label>
                <flux:input wire:model="documentTitle" placeholder="{{ __('e.g. MBBS Degree') }}" required />
                <flux:error name="documentTitle" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Document Type') }}</flux:label>
                <flux:select wire:model="documentType" required>
                    <flux:select.option value="degree">{{ __('Degree') }}</flux:select.option>
                    <flux:select.option value="certificate">{{ __('Certificate') }}</flux:select.option>
                    <flux:select.option value="license">{{ __('License') }}</flux:select.option>
                    <flux:select.option value="contract">{{ __('Contract') }}</flux:select.option>
                    <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
                </flux:select>
                <flux:error name="documentType" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('File') }}</flux:label>
                <flux:input type="file" wire:model="documentFile" required />
                <flux:error name="documentFile" />
            </flux:field>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Issue Date') }}</flux:label>
                    <flux:input type="date" wire:model="documentIssueDate" />
                    <flux:error name="documentIssueDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Expiry Date') }}</flux:label>
                    <flux:input type="date" wire:model="documentExpiryDate" />
                    <flux:error name="documentExpiryDate" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="documentNotes" rows="3" />
                <flux:error name="documentNotes" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeDocumentModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Upload') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Qualification Modal --}}
    <flux:modal wire:model="showQualificationModal" class="w-full max-w-lg">
        <flux:heading level="2">
            {{ $editingQualificationId === null ? __('Add Qualification') : __('Edit Qualification') }}
        </flux:heading>

        <form wire:submit="saveQualification" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Course / Degree / Specialization') }}</flux:label>
                <flux:input wire:model="qualificationCourse" required />
                <flux:error name="qualificationCourse" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Passing Year') }}</flux:label>
                <flux:input type="number" wire:model="qualificationPassingYear" min="1950" max="{{ (int) date('Y') + 1 }}" />
                <flux:error name="qualificationPassingYear" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Institution / Board / University') }}</flux:label>
                <flux:input wire:model="qualificationInstitution" />
                <flux:error name="qualificationInstitution" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Document') }}</flux:label>
                <flux:input type="file" wire:model="qualificationDocument" />
                <flux:error name="qualificationDocument" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeQualificationModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Experience Modal --}}
    <flux:modal wire:model="showExperienceModal" class="w-full max-w-lg">
        <flux:heading level="2">
            {{ $editingExperienceId === null ? __('Add Experience') : __('Edit Experience') }}
        </flux:heading>

        <form wire:submit="saveExperience" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Company') }}</flux:label>
                <flux:input wire:model="experienceCompany" required />
                <flux:error name="experienceCompany" />
            </flux:field>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Date of Joining') }}</flux:label>
                    <flux:input type="date" wire:model="experienceDateOfJoining" required />
                    <flux:error name="experienceDateOfJoining" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Date of Leaving') }}</flux:label>
                    <flux:input type="date" wire:model="experienceDateOfLeaving" />
                    <flux:error name="experienceDateOfLeaving" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Reason for Leaving the Job') }}</flux:label>
                <flux:textarea wire:model="experienceReasonForLeaving" rows="3" />
                <flux:error name="experienceReasonForLeaving" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeExperienceModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add/Edit Todo Modal --}}
    <flux:modal wire:model="showTodoModal" class="w-full max-w-lg">
        <flux:heading level="2">
            {{ $editingTodoId === null ? __('Add Todo') : __('Edit Todo') }}
        </flux:heading>

        <form wire:submit="saveTodo" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Title') }}</flux:label>
                <flux:input wire:model="todoTitle" placeholder="{{ __('e.g. Renew medical license') }}" required />
                <flux:error name="todoTitle" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="todoDescription" rows="3" />
                <flux:error name="todoDescription" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Due Date') }}</flux:label>
                <flux:input type="date" wire:model="todoDueDate" required />
                <flux:error name="todoDueDate" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeTodoModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
