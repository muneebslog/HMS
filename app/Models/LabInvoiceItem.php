<?php

namespace App\Models;

use App\Enums\OutgoingSampleStatus;
use Database\Factories\LabInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LabInvoiceItem extends Model
{
    /** @use HasFactory<LabInvoiceItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lab_invoice_id',
        'lab_test_id',
        'test_name',
        'test_code',
        'sample',
        'time_required',
        'is_in_house',
        'outgoing_status',
        'asked_at',
        'asked_by',
        'given_at',
        'given_by',
        'received_at',
        'received_by',
        'report_path',
        'report_original_name',
        'report_uploaded_at',
        'report_uploaded_by',
        'lab_result_ready',
        'price',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_in_house' => 'boolean',
            'outgoing_status' => OutgoingSampleStatus::class,
            'asked_at' => 'datetime',
            'given_at' => 'datetime',
            'received_at' => 'datetime',
            'report_uploaded_at' => 'datetime',
            'lab_result_ready' => 'boolean',
        ];
    }

    /**
     * Delete the stored report file when the item is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (LabInvoiceItem $item): void {
            if (filled($item->report_path) && Storage::disk('local')->exists($item->report_path)) {
                Storage::disk('local')->delete($item->report_path);
            }
        });
    }

    /**
     * Get the lab invoice for this item.
     */
    public function labInvoice(): BelongsTo
    {
        return $this->belongsTo(LabInvoice::class);
    }

    /**
     * Get the lab test for this item.
     */
    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class);
    }

    /**
     * Get the user who marked the sample as asked.
     *
     * @return BelongsTo<User, $this>
     */
    public function askedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    /**
     * Get the user who marked the sample as given.
     *
     * @return BelongsTo<User, $this>
     */
    public function givenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    /**
     * Get the user who marked the sample as received.
     *
     * @return BelongsTo<User, $this>
     */
    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Get the user who uploaded the outgoing report PDF.
     *
     * @return BelongsTo<User, $this>
     */
    public function reportUploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'report_uploaded_by');
    }

    /**
     * Determine whether this item is an outgoing sample.
     */
    public function isOutgoing(): bool
    {
        return ! $this->is_in_house;
    }

    /**
     * Determine whether a report PDF is stored for this item.
     */
    public function hasReport(): bool
    {
        return filled($this->report_path) && Storage::disk('local')->exists($this->report_path);
    }
}
