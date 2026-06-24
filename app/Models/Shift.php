<?php

namespace App\Models;

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
     * Scope a query to only include open shifts for the given user.
     */
    public function scopeOpenForUser($query, int $userId)
    {
        return $query->where('user_id', $userId)->where('status', 'open');
    }

    /**
     * Get the currently open shift for the given user, if any.
     */
    public static function currentForUser(int $userId): ?self
    {
        return self::openForUser($userId)->latest('opened_at')->first();
    }

    /**
     * Get the total walk-in invoice sales for this shift.
     */
    public function totalWalkInSales(): float
    {
        return $this->invoices()->sum('total') ?: 0.0;
    }

    /**
     * Get the total lab invoice sales for this shift.
     */
    public function totalLabSales(): float
    {
        return $this->labInvoices()->sum('total') ?: 0.0;
    }

    /**
     * Get the total procedure payments for this shift.
     */
    public function totalProcedureSales(): float
    {
        return $this->procedures()
            ->withSum('payments', 'amount')
            ->get()
            ->sum('payments_sum_amount') ?: 0.0;
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
        return $this->expenses()->sum('amount') ?: 0.0;
    }

    /**
     * Get the total daily doctor payouts recorded during this shift.
     */
    public function totalDailyPayouts(): float
    {
        return $this->doctorPayouts()->sum('share_amount') ?: 0.0;
    }

    /**
     * Get the expected cash for this shift.
     */
    public function expectedCash(): float
    {
        return $this->opening_balance
            + $this->totalSales()
            - $this->totalDailyPayouts()
            - $this->totalExpenses();
    }
}
