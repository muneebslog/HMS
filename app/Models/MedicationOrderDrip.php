<?php

namespace App\Models;

use App\Enums\DripLineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property DripLineStatus $status
 */
class MedicationOrderDrip extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'medication_order_id',
        'drip_base_id',
        'name',
        'status',
        'started_at',
        'started_by_health_aide_id',
        'check_due_at',
        'check_notified_at',
        'done_at',
        'done_by_health_aide_id',
        'done_by_user_id',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DripLineStatus::class,
            'started_at' => 'datetime',
            'check_due_at' => 'datetime',
            'check_notified_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MedicationOrder, $this>
     */
    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class);
    }

    /**
     * @return BelongsTo<DripBase, $this>
     */
    public function dripBase(): BelongsTo
    {
        return $this->belongsTo(DripBase::class);
    }

    /**
     * @return HasMany<MedicationOrderDripAdditive, $this>
     */
    public function additives(): HasMany
    {
        return $this->hasMany(MedicationOrderDripAdditive::class);
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function startedByHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'started_by_health_aide_id');
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function doneByHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'done_by_health_aide_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doneByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_user_id');
    }

    /**
     * Whether the drip is still pending or running at the drip station.
     */
    public function isActive(): bool
    {
        return in_array($this->status, DripLineStatus::activeCases(), true);
    }

    /**
     * Whether the 30-minute check is overdue.
     */
    public function isCheckDue(): bool
    {
        return $this->status === DripLineStatus::Started
            && $this->check_due_at !== null
            && $this->check_due_at->isPast();
    }
}
