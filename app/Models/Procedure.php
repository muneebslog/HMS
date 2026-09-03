<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ProcedureFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Procedure extends Model
{
    /** @use HasFactory<ProcedureFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'procedure_type_id',
        'name',
        'expected_delivery_date',
        'full_amount',
        'room_number',
        'room_id',
        'admitted_at',
        'file_printed_at',
        'file_printed_by',
        'consent_completed_at',
        'pre_op_completed_at',
        'pre_op_done_by',
        'pre_op_completed_by',
        'operation_started_at',
        'operation_completed_at',
        'post_op_completed_at',
        'post_op_completed_by',
        'discharged_at',
        'discharged_by',
        'doctor_id',
        'created_by',
        'shift_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'full_amount' => 'float',
            'expected_delivery_date' => 'date',
            'admitted_at' => 'datetime',
            'file_printed_at' => 'datetime',
            'consent_completed_at' => 'datetime',
            'pre_op_completed_at' => 'datetime',
            'operation_started_at' => 'datetime',
            'operation_completed_at' => 'datetime',
            'post_op_completed_at' => 'datetime',
            'discharged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<ProcedureType, $this>
     */
    public function procedureType(): BelongsTo
    {
        return $this->belongsTo(ProcedureType::class);
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filePrinter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'file_printed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preOpCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pre_op_completed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postOpCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'post_op_completed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function discharger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discharged_by');
    }

    /**
     * @return HasMany<ProcedurePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ProcedurePayment::class);
    }

    /**
     * Payments that still count toward collected totals.
     *
     * @return HasMany<ProcedurePayment, $this>
     */
    public function activePayments(): HasMany
    {
        return $this->hasMany(ProcedurePayment::class)->active();
    }

    /**
     * @return HasMany<ProcedureAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ProcedureAttachment::class);
    }

    /**
     * @return HasMany<ProcedureVital, $this>
     */
    public function vitals(): HasMany
    {
        return $this->hasMany(ProcedureVital::class);
    }

    /**
     * @return HasMany<ProcedureFetalHeart, $this>
     */
    public function fetalHearts(): HasMany
    {
        return $this->hasMany(ProcedureFetalHeart::class);
    }

    /**
     * @return HasOne<ProcedurePreOpOrder, $this>
     */
    public function preOpOrder(): HasOne
    {
        return $this->hasOne(ProcedurePreOpOrder::class);
    }

    /**
     * @return HasOne<ProcedureOperationNote, $this>
     */
    public function operationNote(): HasOne
    {
        return $this->hasOne(ProcedureOperationNote::class);
    }

    /**
     * @return HasOne<ProcedureDeliveryNote, $this>
     */
    public function deliveryNote(): HasOne
    {
        return $this->hasOne(ProcedureDeliveryNote::class);
    }

    /**
     * @return HasOne<ProcedurePostOpOrder, $this>
     */
    public function postOpOrder(): HasOne
    {
        return $this->hasOne(ProcedurePostOpOrder::class);
    }

    /**
     * @return HasMany<ProcedureProgressNote, $this>
     */
    public function progressNotes(): HasMany
    {
        return $this->hasMany(ProcedureProgressNote::class);
    }

    /**
     * @return HasMany<ProcedureMedication, $this>
     */
    public function medications(): HasMany
    {
        return $this->hasMany(ProcedureMedication::class);
    }

    /**
     * @return HasMany<ProcedureDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProcedureDocument::class);
    }

    /**
     * @return HasOne<ProcedureDischargeDetail, $this>
     */
    public function dischargeDetail(): HasOne
    {
        return $this->hasOne(ProcedureDischargeDetail::class);
    }

    /**
     * Get the total amount paid for this procedure.
     */
    public function totalPaid(): float
    {
        return (float) $this->activePayments()->sum('amount');
    }

    /**
     * Get the remaining balance for this procedure.
     */
    public function balance(): float
    {
        return $this->full_amount - $this->totalPaid();
    }

    /**
     * Determine whether the procedure has been fully paid.
     */
    public function isPaid(): bool
    {
        return $this->balance() <= 0;
    }

    /**
     * Determine whether the patient has been admitted for this procedure.
     */
    public function isAdmitted(): bool
    {
        return $this->admitted_at !== null;
    }

    /**
     * Determine whether the procedure file has been printed.
     */
    public function isFilePrinted(): bool
    {
        return $this->file_printed_at !== null;
    }

    /**
     * Determine whether the patient has been discharged.
     */
    public function isDischarged(): bool
    {
        return $this->discharged_at !== null;
    }

    /**
     * Scope admitted patients who are still on the ward.
     *
     * @param  Builder<Procedure>  $query
     * @return Builder<Procedure>
     */
    public function scopeOnWard(Builder $query): Builder
    {
        return $query->whereNotNull('admitted_at')->whereNull('discharged_at');
    }

    /**
     * Determine whether vitals are overdue for the previous completed hour.
     */
    public function isVitalsOverdue(?CarbonInterface $reference = null): bool
    {
        if (! $this->isAdmitted() || $this->isDischarged()) {
            return false;
        }

        $reference ??= now();
        $hourStart = $reference->copy()->subHour()->startOfHour();
        $hourEnd = $hourStart->copy()->endOfHour();

        if ($this->admitted_at !== null && $this->admitted_at->gt($hourEnd)) {
            return false;
        }

        return ! $this->vitals()
            ->whereBetween('recorded_at', [$hourStart, $hourEnd])
            ->exists();
    }

    /**
     * Determine whether fetal heart readings are overdue for the previous completed hour.
     */
    public function isFetalHeartOverdue(?CarbonInterface $reference = null): bool
    {
        if (! $this->procedureType?->requires_fetal_heart) {
            return false;
        }

        if (! $this->isAdmitted() || $this->isDischarged()) {
            return false;
        }

        $reference ??= now();
        $hourStart = $reference->copy()->subHour()->startOfHour();
        $hourEnd = $hourStart->copy()->endOfHour();

        if ($this->admitted_at !== null && $this->admitted_at->gt($hourEnd)) {
            return false;
        }

        return ! $this->fetalHearts()
            ->whereBetween('recorded_at', [$hourStart, $hourEnd])
            ->exists();
    }
}
