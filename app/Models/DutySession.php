<?php

namespace App\Models;

use Database\Factories\DutySessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DutySession extends Model
{
    /** @use HasFactory<DutySessionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'duty_date',
        'starts_at',
        'ends_at',
        'in_punch_id',
        'out_punch_id',
        'break_minutes',
        'notes',
        'confirmed_by',
        'confirmed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duty_date' => 'date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'break_minutes' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Whether the arrival time was typed in instead of coming from a punch.
     */
    public function startIsManual(): bool
    {
        return $this->in_punch_id === null;
    }

    /**
     * Whether the leaving time was typed in instead of coming from a punch.
     */
    public function endIsManual(): bool
    {
        return $this->out_punch_id === null;
    }

    /**
     * Minutes between arriving and leaving, without breaks.
     */
    public function workedMinutes(): int
    {
        return max(0, (int) $this->starts_at->diffInMinutes($this->ends_at) - $this->break_minutes);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<AttendanceDuty, $this>
     */
    public function duties(): HasMany
    {
        return $this->hasMany(AttendanceDuty::class)->orderBy('sort_order');
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
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
