<?php

namespace App\Models;

use App\Enums\PrintJobStatus;
use Database\Factories\PdfPrintJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfPrintJob extends Model
{
    /** @use HasFactory<PdfPrintJobFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'original_filename',
        'disk_path',
        'copies',
        'status',
        'attempts',
        'printed_at',
        'failed_at',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => PrintJobStatus::class,
            'copies' => 'integer',
            'attempts' => 'integer',
            'printed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who uploaded this PDF print job.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include pending PDF print jobs.
     *
     * @param  Builder<PdfPrintJob>  $query
     * @return Builder<PdfPrintJob>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PrintJobStatus::Pending->value);
    }

    /**
     * Mark the PDF print job as printed.
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
     * Mark the PDF print job as failed.
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
