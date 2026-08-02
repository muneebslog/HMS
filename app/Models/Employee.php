<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'father_name',
        'cnic',
        'date_of_birth',
        'sex',
        'religion_sect',
        'caste',
        'marital_status',
        'email',
        'phone',
        'current_address',
        'permanent_address',
        'emergency_contact',
        'languages',
        'distance_time_from_hospital',
        'designation',
        'department',
        'joining_date',
        'employment_type',
        'status',
        'notes',
        'photo_path',
        'undertaking_accepted',
        'undertaking_accepted_at',
        'user_id',
        'doctor_id',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'date_of_birth' => 'date',
            'undertaking_accepted' => 'boolean',
            'undertaking_accepted_at' => 'datetime',
        ];
    }

    /**
     * Scope the query to only active employees.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Computed age in years from date of birth.
     *
     * @return Attribute<int|null, never>
     */
    protected function age(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->date_of_birth?->age,
        );
    }

    /**
     * The user account linked to this employee, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The doctor profile linked to this employee, if any.
     *
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * The admin user who created this profile.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The documents attached to this employee.
     *
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->latest();
    }

    /**
     * The todos attached to this employee.
     *
     * @return HasMany<EmployeeTodo, $this>
     */
    public function todos(): HasMany
    {
        return $this->hasMany(EmployeeTodo::class)->latest('due_date');
    }

    /**
     * The qualifications attached to this employee.
     *
     * @return HasMany<EmployeeQualification, $this>
     */
    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class)->latest('passing_year');
    }

    /**
     * The work experiences attached to this employee.
     *
     * @return HasMany<EmployeeExperience, $this>
     */
    public function experiences(): HasMany
    {
        return $this->hasMany(EmployeeExperience::class)->latest('date_of_joining');
    }

    /**
     * Determine whether the employee profile is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Determine whether the employee has a profile photo.
     */
    public function hasPhoto(): bool
    {
        return filled($this->photo_path);
    }

    /**
     * Get the URL for the employee's profile photo, if any.
     */
    public function photoUrl(): ?string
    {
        if (! $this->hasPhoto()) {
            return null;
        }

        return route('employee-photos.show', $this);
    }

    /**
     * Get the employee's initials for avatar fallbacks.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
