<?php

namespace App\Models;

use Database\Factories\ProcedureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'name',
        'full_amount',
        'room_number',
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
        ];
    }

    /**
     * Get the patient for this procedure.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the doctor for this procedure.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the user who created this procedure.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the shift this procedure belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the payments associated with this procedure.
     *
     * @return HasMany<ProcedurePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ProcedurePayment::class);
    }

    /**
     * Get the total amount paid for this procedure.
     */
    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
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
}
