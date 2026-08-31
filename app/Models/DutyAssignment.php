<?php

namespace App\Models;

use App\Enums\DutyAssignmentStatus;
use App\Enums\DutyAssignmentType;
use Database\Factories\DutyAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DutyAssignment extends Model
{
    /** @use HasFactory<DutyAssignmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'health_aide_id',
        'duty_shift_template_id',
        'date',
        'starts_at',
        'ends_at',
        'assignment_type',
        'replaces_health_aide_id',
        'health_aide_leave_id',
        'duty_location_id',
        'is_override',
        'station',
        'notes',
        'status',
        'created_by',
        'approved_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'assignment_type' => DutyAssignmentType::class,
            'status' => DutyAssignmentStatus::class,
            'is_override' => 'boolean',
        ];
    }

    /**
     * @param  Builder<DutyAssignment>  $query
     * @return Builder<DutyAssignment>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', DutyAssignmentStatus::Scheduled);
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function healthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class);
    }

    /**
     * @return BelongsTo<DutyShiftTemplate, $this>
     */
    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(DutyShiftTemplate::class, 'duty_shift_template_id');
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function replacesHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'replaces_health_aide_id');
    }

    /**
     * @return BelongsTo<HealthAideLeave, $this>
     */
    public function healthAideLeave(): BelongsTo
    {
        return $this->belongsTo(HealthAideLeave::class);
    }

    /**
     * @return BelongsTo<DutyLocation, $this>
     */
    public function dutyLocation(): BelongsTo
    {
        return $this->belongsTo(DutyLocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasOne<AttendanceRecord, $this>
     */
    public function attendanceRecord(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class);
    }

    /**
     * @return HasOne<AttendanceWorkSession, $this>
     */
    public function workSession(): HasOne
    {
        return $this->hasOne(AttendanceWorkSession::class);
    }
}
