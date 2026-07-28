<?php

namespace App\Models;

use Database\Factories\EmployeeLeaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    /** @use HasFactory<EmployeeLeaveFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'leave_date',
        'replacement_employee_id',
        'duty_start_time',
        'duty_end_time',
        'is_informed',
        'informed_by',
        'notes',
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
            'duty_start_time' => 'datetime:H:i',
            'duty_end_time' => 'datetime:H:i',
            'is_informed' => 'boolean',
        ];
    }

    /**
     * The employee who is on leave.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The employee replacing the person on leave.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function replacementEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replacement_employee_id');
    }

    /**
     * The admin user who created the leave record.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
