<?php

namespace App\Models;

use App\Enums\FinanceShiftPeriod;
use Database\Factories\FinanceCashEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCashEntry extends Model
{
    /** @use HasFactory<FinanceCashEntryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'entry_date',
        'period',
        'amount_collected',
        'amount_short',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'period' => FinanceShiftPeriod::class,
            'amount_collected' => 'float',
            'amount_short' => 'float',
        ];
    }

    /**
     * Get the user who recorded this cash entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Net cash after shortage for this entry.
     */
    public function netAmount(): float
    {
        return $this->amount_collected - $this->amount_short;
    }
}
