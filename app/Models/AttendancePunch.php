<?php

namespace App\Models;

use App\Enums\PunchPairingRole;
use App\Enums\PunchStateSource;
use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'pairing_role',
        'notes',
        'created_by',
        'processed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'punch_state_source' => PunchStateSource::class,
            'pairing_role' => PunchPairingRole::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Whether this punch is excluded from pairing.
     */
    public function isIgnored(): bool
    {
        return $this->pairing_role === PunchPairingRole::Ignore;
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<AttendanceWorkSession, $this>
     */
    public function workSessionAsIn(): HasOne
    {
        return $this->hasOne(AttendanceWorkSession::class, 'in_punch_id');
    }

    /**
     * @return HasOne<AttendanceWorkSession, $this>
     */
    public function workSessionAsOut(): HasOne
    {
        return $this->hasOne(AttendanceWorkSession::class, 'out_punch_id');
    }
}
