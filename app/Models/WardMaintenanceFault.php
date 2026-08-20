<?php

namespace App\Models;

use App\Enums\WardMaintenanceFaultPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entry_id
 * @property string|null $fault_time
 * @property string|null $bed_room
 * @property string|null $description
 * @property WardMaintenanceFaultPriority|null $priority
 * @property string|null $reported_to
 * @property string|null $action_taken
 * @property bool|null $resolved
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property WardMaintenanceEntry $entry
 */
class WardMaintenanceFault extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entry_id',
        'fault_time',
        'bed_room',
        'description',
        'priority',
        'reported_to',
        'action_taken',
        'resolved',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => WardMaintenanceFaultPriority::class,
            'resolved' => 'boolean',
        ];
    }

    /**
     * Get the entry this fault belongs to.
     *
     * @return BelongsTo<WardMaintenanceEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(WardMaintenanceEntry::class, 'entry_id');
    }
}
