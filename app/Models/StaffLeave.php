<?php

namespace App\Models;

use App\Enums\LeaveKind;
use Database\Factories\StaffLeaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLeave extends Model
{
    /** @use HasFactory<StaffLeaveFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'leave_date',
        'kind',
        'days',
        'paid_days',
        'force_paid',
        'reason',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leave_date' => 'date',
            'kind' => LeaveKind::class,
            'days' => 'float',
            'paid_days' => 'float',
            'force_paid' => 'boolean',
        ];
    }

    /**
     * Days of this leave that are deducted from pay.
     */
    public function unpaidDays(): float
    {
        return max(0, $this->days - $this->paid_days);
    }

    public function isHalfDay(): bool
    {
        return $this->days < 1;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
