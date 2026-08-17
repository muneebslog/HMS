<?php

namespace App\Models;

use Database\Factories\QueueTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $arrived_at
 * @property Carbon|null $displayed_at
 */
class QueueToken extends Model
{
    /** @use HasFactory<QueueTokenFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_queue_id',
        'invoice_item_id',
        'patient_id',
        'token_number',
        'status',
        'origin',
        'arrived_at',
        'displayed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'displayed_at' => 'datetime',
        ];
    }

    /**
     * Get the queue this token belongs to.
     *
     * @return BelongsTo<ServiceQueue, $this>
     */
    public function serviceQueue(): BelongsTo
    {
        return $this->belongsTo(ServiceQueue::class);
    }

    /**
     * Get the invoice item this token was issued for.
     *
     * @return BelongsTo<InvoiceItem, $this>
     */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    /**
     * Get the patient associated with this token.
     *
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get all vitals recorded for this token (newest first).
     *
     * @return HasMany<Vital, $this>
     */
    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class)->latest('id');
    }

    /**
     * Get the latest vitals recorded for this token.
     *
     * @return HasOne<Vital, $this>
     */
    public function vital(): HasOne
    {
        return $this->hasOne(Vital::class)->latestOfMany();
    }

    /**
     * Get the latest medication order for this token.
     *
     * @return HasOne<MedicationOrder, $this>
     */
    public function medicationOrder(): HasOne
    {
        return $this->hasOne(MedicationOrder::class)->latestOfMany();
    }

    /**
     * Get all medication orders for this token.
     *
     * @return HasMany<MedicationOrder, $this>
     */
    public function medicationOrders(): HasMany
    {
        return $this->hasMany(MedicationOrder::class)->latest('id');
    }

    /**
     * Get the doctor recheck timers for this token.
     *
     * @return HasMany<DoctorRecheck, $this>
     */
    public function doctorRechecks(): HasMany
    {
        return $this->hasMany(DoctorRecheck::class);
    }

    /**
     * Get the active (not acknowledged) recheck for this token.
     *
     * @return HasOne<DoctorRecheck, $this>
     */
    public function activeRecheck(): HasOne
    {
        return $this->hasOne(DoctorRecheck::class)
            ->ofMany(
                ['id' => 'max'],
                fn ($query) => $query->whereNull('acknowledged_at')
            );
    }

    /**
     * Get the call records for this token.
     *
     * @return HasMany<PatientCall, $this>
     */
    public function patientCalls(): HasMany
    {
        return $this->hasMany(PatientCall::class);
    }
}
