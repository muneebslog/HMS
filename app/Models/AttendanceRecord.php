<?php

namespace App\Models;

use App\Enums\AttendanceRecordStatus;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'health_aide_id',
        'duty_assignment_id',
        'date',
        'scheduled_starts_at',
        'scheduled_ends_at',
        'first_in_at',
        'last_out_at',
        'worked_minutes',
        'late_minutes',
        'early_leave_minutes',
        'overtime_minutes',
        'payable_minutes',
        'status',
        'is_manual_override',
        'override_reason',
        'overridden_by',
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
            'scheduled_starts_at' => 'datetime',
            'scheduled_ends_at' => 'datetime',
            'first_in_at' => 'datetime',
            'last_out_at' => 'datetime',
            'status' => AttendanceRecordStatus::class,
            'is_manual_override' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function healthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class);
    }

    /**
     * @return BelongsTo<DutyAssignment, $this>
     */
    public function dutyAssignment(): BelongsTo
    {
        return $this->belongsTo(DutyAssignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * @return HasMany<AttendanceAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(AttendanceAdjustment::class);
    }
}
