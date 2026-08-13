<?php

namespace App\Models;

use App\Enums\StationType;
use Database\Factories\StationSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property StationType $station
 * @property Carbon|null $authenticated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_seen_at
 */
class StationSession extends Model
{
    /** @use HasFactory<StationSessionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'station',
        'health_aide_id',
        'authenticated_at',
        'expires_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'station' => StationType::class,
            'authenticated_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function healthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class);
    }

    /**
     * Whether the station login has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null || $this->health_aide_id === null) {
            return true;
        }

        return $this->expires_at->lessThanOrEqualTo(now());
    }

    /**
     * Minutes remaining before expiry, or null if none/expired.
     */
    public function minutesRemaining(): ?int
    {
        if ($this->isExpired()) {
            return null;
        }

        return max(0, (int) now()->diffInMinutes($this->expires_at, false));
    }
}
