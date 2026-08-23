<?php

namespace App\Models;

use App\Enums\WorkSessionStatus;
use Database\Factories\AttendanceWorkSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceWorkSession extends Model
{
    /** @use HasFactory<AttendanceWorkSessionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'health_aide_id',
        'in_punch_id',
        'out_punch_id',
        'starts_at',
        'ends_at',
        'status',
        'duty_assignment_id',
        'worked_minutes',
        'late_minutes',
        'confirmed_by',
        'confirmed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => WorkSessionStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<AttendanceWorkSession>  $query
     * @return Builder<AttendanceWorkSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', WorkSessionStatus::Open);
    }

    /**
     * @param  Builder<AttendanceWorkSession>  $query
     * @return Builder<AttendanceWorkSession>
     */
    public function scopeSuggested(Builder $query): Builder
    {
        return $query->where('status', WorkSessionStatus::Suggested);
    }

    /**
     * @param  Builder<AttendanceWorkSession>  $query
     * @return Builder<AttendanceWorkSession>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', WorkSessionStatus::Confirmed);
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function healthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class);
    }

    /**
     * @return BelongsTo<AttendancePunch, $this>
     */
    public function inPunch(): BelongsTo
    {
        return $this->belongsTo(AttendancePunch::class, 'in_punch_id');
    }

    /**
     * @return BelongsTo<AttendancePunch, $this>
     */
    public function outPunch(): BelongsTo
    {
        return $this->belongsTo(AttendancePunch::class, 'out_punch_id');
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
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return HasOne<AttendanceRecord, $this>
     */
    public function attendanceRecord(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class);
    }
}
