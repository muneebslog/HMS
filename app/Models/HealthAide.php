<?php

namespace App\Models;

use Database\Factories\HealthAideFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class HealthAide extends Model
{
    /** @use HasFactory<HealthAideFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'pin',
        'is_active',
        'device_user_id',
        'attendance_enrolled_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pin',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pin' => 'hashed',
            'is_active' => 'boolean',
            'attendance_enrolled_at' => 'datetime',
        ];
    }

    /**
     * Scope the query to only active health aides.
     *
     * @param  Builder<HealthAide>  $query
     * @return Builder<HealthAide>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<DutyAssignment, $this>
     */
    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }

    /**
     * @return HasMany<AttendancePunch, $this>
     */
    public function attendancePunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * @return HasMany<HealthAideLeave, $this>
     */
    public function leaves(): HasMany
    {
        return $this->hasMany(HealthAideLeave::class);
    }

    /**
     * Determine whether the health aide is enrolled on the attendance device.
     */
    public function isAttendanceEnrolled(): bool
    {
        return filled($this->device_user_id) && $this->attendance_enrolled_at !== null;
    }

    /**
     * Find an active health aide by plain PIN code.
     */
    public static function findByPin(string $plainPin): ?self
    {
        $plainPin = trim($plainPin);

        if ($plainPin === '') {
            return null;
        }

        foreach (static::query()->active()->get() as $aide) {
            if (Hash::check($plainPin, $aide->pin)) {
                return $aide;
            }
        }

        return null;
    }

    /**
     * Whether the plain PIN is already used by another active health aide.
     */
    public static function pinIsTaken(string $plainPin, ?int $ignoreId = null): bool
    {
        $query = static::query()->active();

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        foreach ($query->get() as $aide) {
            if (Hash::check($plainPin, $aide->pin)) {
                return true;
            }
        }

        return false;
    }
}
