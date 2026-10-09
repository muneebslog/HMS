<?php

namespace App\Models;

use Database\Factories\AttendanceDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'comm_key',
        'is_active',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'comm_key',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'port' => 4370,
        'comm_key' => 0,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'comm_key' => 'integer',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Scope the query to only active devices.
     *
     * @param  Builder<AttendanceDevice>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<AttendanceDeviceUser, $this>
     */
    public function deviceUsers(): HasMany
    {
        return $this->hasMany(AttendanceDeviceUser::class);
    }

    /**
     * @return HasMany<AttendancePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }
}
