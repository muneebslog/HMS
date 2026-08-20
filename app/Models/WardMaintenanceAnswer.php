<?php

namespace App\Models;

use App\Enums\WardMaintenanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entry_id
 * @property string $section
 * @property string $item_key
 * @property string $location_key
 * @property WardMaintenanceStatus|null $status
 * @property bool|null $available
 * @property bool|null $functional
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property WardMaintenanceEntry $entry
 */
class WardMaintenanceAnswer extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entry_id',
        'section',
        'item_key',
        'location_key',
        'status',
        'available',
        'functional',
        'remarks',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WardMaintenanceStatus::class,
            'available' => 'boolean',
            'functional' => 'boolean',
        ];
    }

    /**
     * Get the entry this answer belongs to.
     *
     * @return BelongsTo<WardMaintenanceEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(WardMaintenanceEntry::class, 'entry_id');
    }

    /**
     * Determine whether this answer represents a fault.
     */
    public function isFault(): bool
    {
        if ($this->status === WardMaintenanceStatus::Fault) {
            return true;
        }

        return $this->available === false || $this->functional === false;
    }
}
