<?php

namespace App\Models;

use App\Enums\WardMaintenanceShift;
use Database\Factories\WardMaintenanceEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $checklist_date
 * @property WardMaintenanceShift $shift
 * @property string $checked_by_name
 * @property string|null $supervisor_name
 * @property string|null $checked_by_time
 * @property string|null $supervisor_time
 * @property string|null $patient_safety_fault
 * @property string|null $patient_safety_reported
 * @property string|null $room_unavailable
 * @property string|null $beds_out_of_service
 * @property string|null $reason_remarks
 * @property string|null $supervisor_remarks
 * @property Carbon $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 */
class WardMaintenanceEntry extends Model
{
    /** @use HasFactory<WardMaintenanceEntryFactory> */
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
        'checked_by_name',
        'supervisor_name',
        'checked_by_time',
        'supervisor_time',
        'patient_safety_fault',
        'patient_safety_reported',
        'room_unavailable',
        'beds_out_of_service',
        'reason_remarks',
        'supervisor_remarks',
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
            'shift' => WardMaintenanceShift::class,
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
     * @return HasMany<WardMaintenanceAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(WardMaintenanceAnswer::class, 'entry_id');
    }

    /**
     * Get the fault report rows for this entry.
     *
     * @return HasMany<WardMaintenanceFault, $this>
     */
    public function faults(): HasMany
    {
        return $this->hasMany(WardMaintenanceFault::class, 'entry_id')->orderBy('sort_order');
    }

    /**
     * Determine whether this entry contains any fault signals.
     */
    public function hasFaults(): bool
    {
        if ($this->patient_safety_fault === 'yes') {
            return true;
        }

        if ($this->relationLoaded('answers')) {
            $hasFaultAnswer = $this->answers->contains(
                fn (WardMaintenanceAnswer $answer) => $answer->isFault()
            );
        } else {
            $hasFaultAnswer = $this->answers()
                ->where(function ($query): void {
                    $query->where('status', 'fault')
                        ->orWhere('available', false)
                        ->orWhere('functional', false);
                })
                ->exists();
        }

        if ($hasFaultAnswer) {
            return true;
        }

        if ($this->relationLoaded('faults')) {
            return $this->faults->isNotEmpty();
        }

        return $this->faults()->exists();
    }
}
