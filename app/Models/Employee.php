<?php

namespace App\Models;

use App\Enums\StaffCategory;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'staff_category',
        'tracks_attendance',
        'expected_monthly_duties',
        'default_shift_template_id',
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
            'staff_category' => StaffCategory::class,
            'tracks_attendance' => 'boolean',
            'expected_monthly_duties' => 'integer',
        ];
    }

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'staff_category' => 'non_medical',
        'tracks_attendance' => true,
    ];

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

    /**
     * Scope the query to staff who punch on the attendance machine.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeTracksAttendance(Builder $query): Builder
    {
        return $query->where('tracks_attendance', true);
    }

    /**
     * The kiosk PIN identity for this employee when they are a health aide.
     *
     * @return HasOne<HealthAide, $this>
     */
    public function healthAide(): HasOne
    {
        return $this->hasOne(HealthAide::class);
    }

    /**
     * Attendance machine users linked to this employee.
     *
     * @return HasMany<AttendanceDeviceUser, $this>
     */
    public function attendanceDeviceUsers(): HasMany
    {
        return $this->hasMany(AttendanceDeviceUser::class);
    }

    /**
     * The shift this person usually works; preferred when pairing suggests duties.
     *
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function defaultShiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'default_shift_template_id');
    }

    /**
     * Confirmed stretches of work from the pairing desk.
     *
     * @return HasMany<DutySession, $this>
     */
    public function dutySessions(): HasMany
    {
        return $this->hasMany(DutySession::class);
    }

    /**
     * Salary history, newest first.
     *
     * @return HasMany<EmployeeSalary, $this>
     */
    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class)->latest('effective_from');
    }

    /**
     * The salary record in force on the given date.
     */
    public function salaryOn(CarbonInterface $date): ?EmployeeSalary
    {
        return $this->salaries()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->first();
    }

    /**
     * Duties expected in the month of the given date (one per calendar day unless overridden).
     */
    public function expectedDutiesFor(CarbonInterface $month): int
    {
        return $this->expected_monthly_duties ?? $month->daysInMonth;
    }

    /**
     * The next birthday on or after the given date, if the date of birth is known.
     */
    public function nextBirthday(CarbonInterface $from): ?CarbonInterface
    {
        if ($this->date_of_birth === null) {
            return null;
        }

        $from = $from->copy()->startOfDay();

        foreach ([$from->year, $from->year + 1] as $year) {
            $birthday = $from->copy()->setDate($year, $this->date_of_birth->month, 1);
            $birthday = $birthday->setDay(min($this->date_of_birth->day, $birthday->daysInMonth));

            if ($birthday->gte($from)) {
                return $birthday;
            }
        }

        return null;
    }
}
