<?php

namespace App\Models;

use App\Enums\DripChargeStatus;
use App\Enums\DripLineStatus;
use App\Enums\MedicationOrderStatus;
use Database\Factories\MedicationOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MedicationOrderStatus $status
 */
class MedicationOrder extends Model
{
    /** @use HasFactory<MedicationOrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_token_id',
        'patient_id',
        'doctor_id',
        'prescribed_by',
        'status',
        'complaint_or_diagnosis',
        'notes',
        'administered_by',
        'administered_by_health_aide_id',
        'administered_at',
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
            'status' => MedicationOrderStatus::class,
            'administered_at' => 'datetime',
        ];
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
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function prescribedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by');
    }

    /**
     * Symptoms recorded as the diagnosis for this order.
     *
     * @return BelongsToMany<Symptom, $this>
     */
    public function symptoms(): BelongsToMany
    {
        return $this->belongsToMany(Symptom::class)->withTimestamps()->orderBy('symptoms.name');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function administeredByHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'administered_by_health_aide_id');
    }

    /**
     * Whether all medicines and injections on this order have been delivered.
     * Returns false when the order has no medicine or injection lines.
     */
    public function allMedicinesAndInjectionsDelivered(): bool
    {
        $medicineCount = $this->medicines()->count();
        $injectionCount = $this->injections()->count();

        if ($medicineCount === 0 && $injectionCount === 0) {
            return false;
        }

        $hasUndeliveredMedicine = $this->medicines()->whereNull('delivered_at')->exists();
        $hasUndeliveredInjection = $this->injections()->whereNull('delivered_at')->exists();

        return ! $hasUndeliveredMedicine && ! $hasUndeliveredInjection;
    }

    /**
     * Whether any drip line still has to be run at the drip station.
     * Medicines and injections wait at ER until every drip is done.
     */
    public function hasActiveDrips(): bool
    {
        if ($this->relationLoaded('drips')) {
            return $this->drips->contains(fn (MedicationOrderDrip $drip): bool => $drip->isActive());
        }

        return $this->drips()->whereIn('status', DripLineStatus::activeCases())->exists();
    }

    /**
     * Whether a drip charge is still waiting to be paid at reception.
     */
    public function hasUnpaidDripCharge(): bool
    {
        if ($this->relationLoaded('dripCharges')) {
            return $this->dripCharges->contains(
                fn (DripCharge $charge): bool => $charge->status === DripChargeStatus::Pending
            );
        }

        return $this->dripCharges()->where('status', DripChargeStatus::Pending)->exists();
    }

    /**
     * Why ER cannot deliver this order yet, if it is held.
     *
     * @return 'unpaid_and_drip'|'unpaid'|'drip'|null
     */
    public function erHoldReason(): ?string
    {
        $unpaid = $this->hasUnpaidDripCharge();
        $dripPending = $this->hasActiveDrips();

        if ($unpaid && $dripPending) {
            return 'unpaid_and_drip';
        }

        if ($unpaid) {
            return 'unpaid';
        }

        if ($dripPending) {
            return 'drip';
        }

        return null;
    }

    /**
     * Staff-facing reason this order is locked on the ER board.
     */
    public function erHoldMessage(): ?string
    {
        return match ($this->erHoldReason()) {
            'unpaid_and_drip' => __('Not yet paid. Drip not yet given.'),
            'unpaid' => __('Not yet paid.'),
            'drip' => __('Drip not yet given.'),
            default => null,
        };
    }

    /**
     * Mark the order administered by a health aide when all meds/injections are delivered.
     */
    public function markAdministeredByHealthAide(HealthAide $aide): void
    {
        if ($this->status === MedicationOrderStatus::Administered) {
            return;
        }

        if (! $this->allMedicinesAndInjectionsDelivered()) {
            return;
        }

        $this->update([
            'status' => MedicationOrderStatus::Administered,
            'administered_by_health_aide_id' => $aide->id,
            'administered_at' => now(),
        ]);
    }

    /**
     * @return HasMany<MedicationOrderMedicine, $this>
     */
    public function medicines(): HasMany
    {
        return $this->hasMany(MedicationOrderMedicine::class);
    }

    /**
     * @return HasMany<MedicationOrderInjection, $this>
     */
    public function injections(): HasMany
    {
        return $this->hasMany(MedicationOrderInjection::class);
    }

    /**
     * @return HasMany<MedicationOrderDrip, $this>
     */
    public function drips(): HasMany
    {
        return $this->hasMany(MedicationOrderDrip::class);
    }

    /**
     * @return HasMany<DripCharge, $this>
     */
    public function dripCharges(): HasMany
    {
        return $this->hasMany(DripCharge::class);
    }
}
