<?php

namespace App\Models;

use Database\Factories\AttendanceDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDevice extends Model
{
    /** @use HasFactory<AttendanceDeviceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'serial_number',
        'settings',
        'last_sync_at',
        'last_sync_status',
        'last_sync_error',
        'consecutive_sync_failures',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_sync_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AttendancePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    /**
     * @return HasMany<AttendanceDeviceUser, $this>
     */
    public function deviceUsers(): HasMany
    {
        return $this->hasMany(AttendanceDeviceUser::class);
    }

    /**
     * Resolve the configured default device, creating it when missing.
     */
    public static function defaultDevice(): self
    {
        $config = config('attendance.device');

        return self::query()->firstOrCreate(
            ['ip_address' => $config['ip_address'], 'port' => $config['port']],
            [
                'name' => $config['name'],
                'is_active' => true,
            ],
        );
    }
}
