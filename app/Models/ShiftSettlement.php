<?php

namespace App\Models;

use App\Enums\FinanceShiftPeriod;
use Database\Factories\ShiftSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSettlement extends Model
{
    /** @use HasFactory<ShiftSettlementFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shift_id',
        'settled_by',
        'business_date',
        'period',
        'opening_balance',
        'cash_sales',
        'online_sales',
        'doctor_payouts',
        'expenses',
        'declared_closing_balance',
        'expected_amount',
        'received_amount',
        'difference',
        'notes',
        'settled_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'period' => FinanceShiftPeriod::class,
            'opening_balance' => 'float',
            'cash_sales' => 'float',
            'online_sales' => 'float',
            'doctor_payouts' => 'float',
            'expenses' => 'float',
            'declared_closing_balance' => 'float',
            'expected_amount' => 'float',
            'received_amount' => 'float',
            'difference' => 'float',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * Get the shift this settlement closes out.
     *
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the user who received the cash and settled the shift.
     *
     * @return BelongsTo<User, $this>
     */
    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * Determine whether less cash was received than expected.
     */
    public function isShort(): bool
    {
        return $this->difference < 0;
    }
}
