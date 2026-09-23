<?php

namespace App\Models;

use App\Enums\FinanceExpenseCategory;
use Database\Factories\FinanceExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceExpense extends Model
{
    /** @use HasFactory<FinanceExpenseFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'category',
        'amount',
        'expense_date',
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
            'category' => FinanceExpenseCategory::class,
            'amount' => 'float',
            'expense_date' => 'date',
        ];
    }

    /**
     * Get the user who recorded this finance expense.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
