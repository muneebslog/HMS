<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMode;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
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
        'total',
        'status',
        'payment_mode',
        'created_by',
        'shift_id',
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
            'total' => 'float',
            'payment_mode' => PaymentMode::class,
            'return_approval_status' => ApprovalStatus::class,
            'return_reviewed_at' => 'datetime',
        ];
    }

    /**
     * Get the patient for this invoice.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the user who created this invoice.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the items associated with this invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Get the shift this invoice belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
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
     * Determine whether this invoice has been returned.
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
     * Generate a unique invoice number.
     */
    public static function generateNumber(): string
    {
        return 'INV-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
    }
}
