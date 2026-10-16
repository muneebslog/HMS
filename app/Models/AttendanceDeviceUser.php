<?php

namespace App\Models;

use Database\Factories\AttendanceDeviceUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDeviceUser extends Model
{
    /** @use HasFactory<AttendanceDeviceUserFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attendance_device_id',
        'device_uid',
        'device_user_id',
        'name',
        'privilege',
        'card_number',
        'employee_id',
        'last_seen_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_uid' => 'integer',
            'privilege' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Whether this user is an administrator on the device itself.
     */
    public function isDeviceAdmin(): bool
    {
        return $this->privilege >= 14;
    }

    /**
     * @return BelongsTo<AttendanceDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    /**
     * The staff profile this machine user is linked to.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<AttendancePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }
}
