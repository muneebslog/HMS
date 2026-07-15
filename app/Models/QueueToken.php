<?php

namespace App\Models;

use Database\Factories\QueueTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Get the call records for this token.
     *
     * @return HasMany<PatientCall, $this>
     */
    public function patientCalls(): HasMany
    {
        return $this->hasMany(PatientCall::class);
    }
}
