<?php

namespace App\Models;

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
     * Determine whether this payment has been discarded.
     */
    public function isDiscarded(): bool
    {
        return $this->discarded_at !== null;
    }

    /**
     * Scope payments that still count toward collected totals.
     *
     * @param  Builder<ProcedurePayment>  $query
     * @return Builder<ProcedurePayment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('discarded_at');
    }
}
