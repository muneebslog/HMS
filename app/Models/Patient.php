<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'name',
        'husband_name',
        'cnic',
        'mrn',
        'age',
        'gender',
    ];

    /**
     * Boot the model and generate an MRN for new patients.
     */
    protected static function booted(): void
    {
        static::created(function (Patient $patient) {
            if (blank($patient->mrn)) {
                $patient->update(['mrn' => 'MRN'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT)]);
            }
        });
    }

    /**
     * Get the family this patient belongs to.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the shared family contact phone, if any.
     */
    public function contactPhone(): ?string
    {
        return $this->family?->phone;
    }

    /**
     * Get the queue tokens associated with this patient.
     *
     * @return HasMany<QueueToken, $this>
     */
    public function queueTokens(): HasMany
    {
        return $this->hasMany(QueueToken::class);
    }

    /**
     * Get the invoices associated with this patient.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the lab invoices associated with this patient.
     *
     * @return HasMany<LabInvoice, $this>
     */
    public function labInvoices(): HasMany
    {
        return $this->hasMany(LabInvoice::class);
    }

    /**
     * Get the procedures associated with this patient.
     *
     * @return HasMany<Procedure, $this>
     */
    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class);
    }

    /**
     * Get the vitals associated with this patient.
     *
     * @return HasMany<Vital, $this>
     */
    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    /**
     * Get the ultrasound reports associated with this patient.
     *
     * @return HasMany<UltrasoundReport, $this>
     */
    public function ultrasoundReports(): HasMany
    {
        return $this->hasMany(UltrasoundReport::class);
    }
}
