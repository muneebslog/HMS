<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMode;
use Database\Factories\LabInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LabInvoice extends Model
{
    /** @use HasFactory<LabInvoiceFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payment_mode' => 'cash',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'invoice_number',
        'subtotal',
        'discount_percentage',
        'discount_amount',
        'total',
        'status',
        'payment_mode',
        'created_by',
        'shift_id',
        'referred_by_doctor_id',
        'doctor_share',
        'return_approval_status',
        'return_requested_by',
        'return_reviewed_by',
        'return_reviewed_at',
        'return_note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discount_percentage' => 'float',
            'discount_amount' => 'float',
            'total' => 'float',
            'doctor_share' => 'float',
            'payment_mode' => PaymentMode::class,
            'return_approval_status' => ApprovalStatus::class,
            'return_reviewed_at' => 'datetime',
        ];
    }

    /**
     * Get the patient for this lab invoice.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the user who created this lab invoice.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the items associated with this lab invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(LabInvoiceItem::class);
    }

    /**
     * Get the shift this lab invoice belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the referring doctor for this lab invoice.
     *
     * @return BelongsTo<Doctor, $this>
     */
    public function referredByDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'referred_by_doctor_id');
    }

    /**
     * Get the user who requested the return.
     *
     * @return BelongsTo<User, $this>
     */
    public function returnRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_requested_by');
    }

    /**
     * Get the user who reviewed the return.
     *
     * @return BelongsTo<User, $this>
     */
    public function returnReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_reviewed_by');
    }

    /**
     * Determine whether this lab invoice has been returned.
     */
    public function isReturned(): bool
    {
        return $this->status === 'returned';
    }

    /**
     * Determine whether this return is awaiting management approval.
     */
    public function isReturnPending(): bool
    {
        return $this->isReturned() && $this->return_approval_status === ApprovalStatus::Pending;
    }

    /**
     * Get the referring doctor's share amount for this invoice.
     */
    public function doctorShareAmount(): float
    {
        if ($this->referred_by_doctor_id === null || $this->doctor_share === null) {
            return 0.0;
        }

        return round($this->total * ($this->doctor_share / 100), 2);
    }

    /**
     * Get the latest lab API log for this invoice.
     */
    public function labApiLog(): HasOne
    {
        return $this->hasOne(LabApiLog::class)->latestOfMany();
    }

    /**
     * Generate a unique lab invoice number.
     *
     * Format: auto-incrementing number starting at 8001.
     */
    public static function generateNumber(): string
    {
        $sequence = LabInvoiceNumberSequence::firstOrCreate(
            ['date' => '2000-01-01'],
            ['last_number' => 928000]
        );

        $sequence->increment('last_number');
        $sequence->refresh();

        return (string) $sequence->last_number;
    }
}
