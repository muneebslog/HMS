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
     * Get the total sales for this shift.
     */
    public function totalSales(): float
    {
        return $this->totalWalkInSales() + $this->totalLabSales();
    }
}
