<?php

namespace App\Models;

use Database\Factories\LabSampleRetakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabSampleRetake extends Model
{
    /** @use HasFactory<LabSampleRetakeFactory> */
    use HasFactory;

    /**
     * Quick reasons offered when the lab asks for a retake.
     *
     * @var list<string>
     */
    public const REASONS = [
        'Sample not received',
        'Hemolysed',
        'Clotted',
        'Insufficient quantity',
        'Wrong container',
        'Sample damaged or spilled',
        'Unlabelled or mislabelled',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lab_invoice_item_id',
        'reason',
        'requested_by',
        'patient_contacted_at',
        'patient_contacted_by',
        'slip_printed_at',
        'slip_printed_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'patient_contacted_at' => 'datetime',
            'slip_printed_at' => 'datetime',
        ];
    }

    /**
     * Get the test the retake is for.
     *
     * @return BelongsTo<LabInvoiceItem, $this>
     */
    public function labInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(LabInvoiceItem::class);
    }

    /**
     * Get the lab user who asked for the retake.
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the reception user who called the patient.
     *
     * @return BelongsTo<User, $this>
     */
    public function patientContactedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_contacted_by');
    }

    /**
     * Get the reception user who printed the retake slip.
     *
     * @return BelongsTo<User, $this>
     */
    public function slipPrintedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'slip_printed_by');
    }

    /**
     * Determine whether the patient has not come back for this retake yet.
     */
    public function isOpen(): bool
    {
        return $this->slip_printed_at === null;
    }

    /**
     * Scope the query to retakes still waiting for the patient to come back.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('slip_printed_at');
    }
}
