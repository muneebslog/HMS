<?php

namespace App\Models;

use App\Enums\PaymentMode;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'closing_balance',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_balance' => 'float',
            'closing_balance' => 'float',
        ];
    }

    /**
     * Get the user who owns this shift.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the walk-in invoices created during this shift.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the lab invoices created during this shift.
     */
    public function labInvoices(): HasMany
    {
        return $this->hasMany(LabInvoice::class);
    }

    /**
     * Get the procedures created during this shift.
     *
     * @return HasMany<Procedure, $this>
     */
    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class);
    }

    /**
     * Get the procedure payments recorded against this shift.
     *
     * @return HasMany<ProcedurePayment, $this>
     */
    public function procedurePayments(): HasMany
    {
        return $this->hasMany(ProcedurePayment::class);
    }

    /**
     * Get the service queues opened during this shift.
     */
    public function serviceQueues(): HasMany
    {
        return $this->hasMany(ServiceQueue::class);
    }

    /**
     * Get the expenses logged during this shift.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Get the doctor payouts recorded during this shift.
     */
    public function doctorPayouts(): HasMany
    {
        return $this->hasMany(DoctorPayout::class);
    }

    /**
     * Scope a query to only include open shifts.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Get the single currently open shift, if any.
     */
    public static function current(): ?self
    {
        return self::open()->latest('opened_at')->first();
    }

    /**
     * Get the total walk-in invoice sales for this shift, optionally for one payment mode.
     */
    public function totalWalkInSales(?PaymentMode $mode = null): float
    {
        return (float) ($this->invoices()
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->when($mode, fn ($query) => $query->where('payment_mode', $mode->value))
            ->sum('total') ?: 0.0);
    }

    /**
     * Get the total lab invoice sales for this shift, optionally for one payment mode.
     */
    public function totalLabSales(?PaymentMode $mode = null): float
    {
        return (float) ($this->labInvoices()
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->when($mode, fn ($query) => $query->where('payment_mode', $mode->value))
            ->sum('total') ?: 0.0);
    }

    /**
     * Get the total procedure payments for this shift, optionally for one payment mode.
     */
    public function totalProcedureSales(?PaymentMode $mode = null): float
    {
        return (float) ($this->procedurePayments()
            ->active()
            ->when($mode, fn ($query) => $query->where('mode', $mode->value))
            ->sum('amount') ?: 0.0);
    }

    /**
     * Get the sales paid online (bank transfer, wallet...) in this shift. This money is not in the drawer.
     */
    public function totalOnlineSales(): float
    {
        return $this->totalWalkInSales(PaymentMode::Online)
            + $this->totalLabSales(PaymentMode::Online)
            + $this->totalProcedureSales(PaymentMode::Online);
    }

    /**
     * Get the sales paid in cash in this shift.
     */
    public function totalCashSales(): float
    {
        return $this->totalSales() - $this->totalOnlineSales();
    }

    /**
     * Get the total sales for this shift.
     */
    public function totalSales(): float
    {
        return $this->totalWalkInSales() + $this->totalLabSales() + $this->totalProcedureSales();
    }

    /**
     * Get the total expenses for this shift.
     */
    public function totalExpenses(): float
    {
        return $this->expenses()->countingTowardCash()->sum('amount') ?: 0.0;
    }

    /**
     * Get the total daily doctor payouts recorded during this shift.
     */
    public function totalDailyPayouts(): float
    {
        return $this->doctorPayouts()->sum('share_amount') ?: 0.0;
    }

    /**
     * Get the cash expected in the drawer for this shift (online payments are not in the drawer).
     */
    public function expectedCash(): float
    {
        return $this->opening_balance
            + $this->totalCashSales()
            - $this->totalDailyPayouts()
            - $this->totalExpenses();
    }
}
