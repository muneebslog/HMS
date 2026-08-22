<?php

namespace App\Models;

use App\Enums\PunchStateSource;
use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePunch extends Model
{
    /** @use HasFactory<AttendancePunchFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attendance_device_id',
        'device_punch_uid',
        'device_user_id',
        'health_aide_id',
        'punched_at',
        'verify_type',
        'punch_state',
        'punch_state_source',
        'processed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'punch_state_source' => PunchStateSource::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttendanceDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function healthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class);
    }
}
