<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMode;
use Database\Factories\ProcedurePaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedurePayment extends Model
{
    /** @use HasFactory<ProcedurePaymentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'amount',
        'mode',
        'created_by',
        'shift_id',
        'discarded_at',
        'discarded_by',
        'returned_at',
        'return_requested_by',
        'return_approval_status',
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
            'amount' => 'float',
            'mode' => PaymentMode::class,
            'discarded_at' => 'datetime',
            'returned_at' => 'datetime',
            'return_approval_status' => ApprovalStatus::class,
            'return_reviewed_at' => 'datetime',
        ];
    }

    /**
     * Get the procedure this payment belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who recorded this payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the shift this payment belongs to.
     *
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the admin who discarded this payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function discarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
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
     * Determine whether this payment has been discarded.
     */
    public function isDiscarded(): bool
    {
        return $this->discarded_at !== null;
    }

    /**
     * Determine whether this payment has been returned.
     */
    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }

    /**
     * Determine whether this return is awaiting management approval.
     */
    public function isReturnPending(): bool
    {
        return $this->isReturned() && $this->return_approval_status === ApprovalStatus::Pending;
    }

    /**
     * Scope payments that still count toward collected totals.
     *
     * @param  Builder<ProcedurePayment>  $query
     * @return Builder<ProcedurePayment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('discarded_at')
            ->whereNull('returned_at');
    }
}
