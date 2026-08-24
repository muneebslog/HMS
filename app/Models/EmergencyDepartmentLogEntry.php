<?php

namespace App\Models;

use App\Enums\EmergencyDepartmentShift;
use Database\Factories\EmergencyDepartmentLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $checklist_date
 * @property EmergencyDepartmentShift $shift
 * @property string $completed_by_name
 * @property string|null $supervisor_name
 * @property string|null $equipment_issues_log
 * @property Carbon $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 */
class EmergencyDepartmentLogEntry extends Model
{
    /** @use HasFactory<EmergencyDepartmentLogEntryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'checklist_date',
        'shift',
        'completed_by_name',
        'supervisor_name',
        'equipment_issues_log',
        'submitted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checklist_date' => 'date',
            'shift' => EmergencyDepartmentShift::class,
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Get the incharge nurse who submitted this entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the checklist answers for this entry.
     *
     * @return HasMany<EmergencyDepartmentLogAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(EmergencyDepartmentLogAnswer::class, 'entry_id');
    }

    /**
     * Determine whether this entry contains any fault signals.
     */
    public function hasFaults(): bool
    {
        if ($this->relationLoaded('answers')) {
            return $this->answers->contains(
                fn (EmergencyDepartmentLogAnswer $answer) => $answer->isFault()
            );
        }

        return $this->answers()
            ->where(function ($query): void {
                $query->where('status', 'issue')
                    ->orWhere('adequate', false)
                    ->orWhere('checked', false);
            })
            ->exists();
    }
}
