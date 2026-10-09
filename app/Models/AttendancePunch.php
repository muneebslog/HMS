<?php

namespace App\Models;

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
        'attendance_device_user_id',
        'device_user_id',
        'punched_at',
        'verify_type',
        'punch_state',
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
            'verify_type' => 'integer',
            'punch_state' => 'integer',
        ];
    }

    /**
     * Human label for the device's punch state (check-in / check-out etc).
     */
    public function punchStateLabel(): string
    {
        return match ($this->punch_state) {
            0 => __('Check in'),
            1 => __('Check out'),
            2 => __('Break out'),
            3 => __('Break in'),
            4 => __('Overtime in'),
            5 => __('Overtime out'),
            default => __('Punch'),
        };
    }

    /**
     * Human label for how the punch was verified on the device.
     */
    public function verifyTypeLabel(): string
    {
        return match ($this->verify_type) {
            1 => __('Fingerprint'),
            0, 3 => __('Password'),
            2, 4 => __('Card'),
            15 => __('Face'),
            default => __('Other'),
        };
    }

    /**
     * @return BelongsTo<AttendanceDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    /**
     * @return BelongsTo<AttendanceDeviceUser, $this>
     */
    public function deviceUser(): BelongsTo
    {
        return $this->belongsTo(AttendanceDeviceUser::class, 'attendance_device_user_id');
    }
}
