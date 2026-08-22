<?php

namespace App\Models;

use Database\Factories\AttendanceAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAdjustment extends Model
{
    /** @use HasFactory<AttendanceAdjustmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attendance_record_id',
        'field_changed',
        'old_value',
        'new_value',
        'reason',
        'created_by',
    ];

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
