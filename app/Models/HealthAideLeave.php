<?php

namespace App\Models;

use Database\Factories\HealthAideLeaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthAideLeave extends Model
{
    /** @use HasFactory<HealthAideLeaveFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'health_aide_id',
        'leave_date',
        'replacement_health_aide_id',
        'duty_start_time',
        'duty_end_time',
        'is_informed',
        'informed_by',
        'notes',
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
            'leave_date' => 'date',
            'duty_start_time' => 'datetime:H:i',
            'duty_end_time' => 'datetime:H:i',
            'is_informed' => 'boolean',
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
     * @return BelongsTo<HealthAide, $this>
     */
    public function replacementHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'replacement_health_aide_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<DutyAssignment, $this>
     */
    public function replacementAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }
}
