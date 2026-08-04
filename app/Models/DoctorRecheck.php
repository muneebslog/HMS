<?php

namespace App\Models;

use Database\Factories\DoctorRecheckFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorRecheck extends Model
{
    /** @use HasFactory<DoctorRecheckFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_token_id',
        'patient_id',
        'set_by',
        'minutes',
        'note',
        'due_at',
        'notified_at',
        'acknowledged_at',
        'vitals_redone_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'due_at' => 'datetime',
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'vitals_redone_at' => 'datetime',
        ];
    }

    /**
     * Rechecks that have not been cleared by the doctor.
     *
     * @param  Builder<DoctorRecheck>  $query
     * @return Builder<DoctorRecheck>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    /**
     * Pending rechecks whose timer has elapsed.
     *
     * @param  Builder<DoctorRecheck>  $query
     * @return Builder<DoctorRecheck>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->pending()->where('due_at', '<=', now());
    }

    /**
     * Due rechecks that still need vitals re-recorded.
     *
     * @param  Builder<DoctorRecheck>  $query
     * @return Builder<DoctorRecheck>
     */
    public function scopeAwaitingVitals(Builder $query): Builder
    {
        return $query->due()->whereNull('vitals_redone_at');
    }

    /**
     * Whether this recheck is due for attention.
     */
    public function isDue(): bool
    {
        return $this->acknowledged_at === null && $this->due_at->lte(now());
    }

    /**
     * Whether vitals were re-recorded after the timer elapsed.
     */
    public function hasVitalsRedone(): bool
    {
        return $this->vitals_redone_at !== null;
    }

    /**
     * Human-readable timer status for admin monitoring.
     */
    public function timerStatus(): string
    {
        if ($this->acknowledged_at !== null) {
            return 'cleared';
        }

        if ($this->due_at->gt(now())) {
            return 'on_timer';
        }

        if ($this->vitals_redone_at !== null) {
            return 'vitals_redone';
        }

        return 'awaiting_vitals';
    }

    /**
     * Translated label for the timer status.
     */
    public function timerStatusLabel(): string
    {
        return match ($this->timerStatus()) {
            'cleared' => __('Cleared'),
            'on_timer' => __('On timer'),
            'vitals_redone' => __('Vitals redone'),
            default => __('Awaiting vitals'),
        };
    }

    /**
     * @return BelongsTo<QueueToken, $this>
     */
    public function queueToken(): BelongsTo
    {
        return $this->belongsTo(QueueToken::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
