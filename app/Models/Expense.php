<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'approval_status' => 'pending',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shift_id',
        'user_id',
        'name',
        'amount',
        'approval_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
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
            'approval_status' => ApprovalStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Get the shift this expense belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the user who logged this expense.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who reviewed this expense.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Determine whether this expense is awaiting approval.
     */
    public function isPendingApproval(): bool
    {
        return $this->approval_status === ApprovalStatus::Pending;
    }

    /**
     * Determine whether this expense was rejected.
     */
    public function isRejected(): bool
    {
        return $this->approval_status === ApprovalStatus::Rejected;
    }

    /**
     * Scope expenses that still count toward shift cash.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeCountingTowardCash(Builder $query): Builder
    {
        return $query->where('approval_status', '!=', ApprovalStatus::Rejected);
    }
}
