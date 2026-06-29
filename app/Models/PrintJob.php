<?php

namespace App\Models;

use App\Enums\PrintJobStatus;
use Database\Factories\PrintJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends Model
{
    /** @use HasFactory<PrintJobFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
        'lab_invoice_id',
        'shift_id',
        'status',
        'payload',
        'attempts',
        'printed_at',
        'failed_at',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PrintJobStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'printed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Get the walk-in invoice associated with this print job.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the lab invoice associated with this print job.
     */
    public function labInvoice(): BelongsTo
    {
        return $this->belongsTo(LabInvoice::class);
    }

    /**
     * Get the shift associated with this print job.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Scope a query to only include pending print jobs.
     */
    public function scopePending($query)
    {
        return $query->where('status', PrintJobStatus::Pending->value);
    }

    /**
     * Mark the print job as printed.
     */
    public function markAsPrinted(): void
    {
        $this->update([
            'status' => PrintJobStatus::Printed,
            'printed_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Mark the print job as failed.
     */
    public function markAsFailed(string $message): void
    {
        $this->update([
            'status' => PrintJobStatus::Failed,
            'failed_at' => now(),
            'error_message' => $message,
            'attempts' => $this->attempts + 1,
        ]);
    }
}
