<?php

namespace App\Models;

use App\Enums\EmergencyDepartmentEquipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entry_id
 * @property string $section
 * @property string $item_key
 * @property int|null $count
 * @property EmergencyDepartmentEquipmentStatus|null $status
 * @property bool|null $adequate
 * @property bool|null $checked
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property EmergencyDepartmentLogEntry $entry
 */
class EmergencyDepartmentLogAnswer extends Model
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
        'count',
        'status',
        'adequate',
        'checked',
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
            'count' => 'integer',
            'status' => EmergencyDepartmentEquipmentStatus::class,
            'adequate' => 'boolean',
            'checked' => 'boolean',
        ];
    }

    /**
     * Get the entry this answer belongs to.
     *
     * @return BelongsTo<EmergencyDepartmentLogEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(EmergencyDepartmentLogEntry::class, 'entry_id');
    }

    /**
     * Determine whether this answer represents a fault.
     */
    public function isFault(): bool
    {
        if ($this->status === EmergencyDepartmentEquipmentStatus::Issue) {
            return true;
        }

        return $this->adequate === false || $this->checked === false;
    }
}
