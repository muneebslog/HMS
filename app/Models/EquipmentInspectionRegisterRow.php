<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entry_id
 * @property Carbon|null $item_date
 * @property string|null $department
 * @property string|null $equipment
 * @property string|null $problem
 * @property string|null $action_taken
 * @property string|null $technician
 * @property Carbon|null $completed_date
 * @property string|null $signed
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property EquipmentInspectionEntry $entry
 */
class EquipmentInspectionRegisterRow extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entry_id',
        'item_date',
        'department',
        'equipment',
        'problem',
        'action_taken',
        'technician',
        'completed_date',
        'signed',
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
            'item_date' => 'date',
            'completed_date' => 'date',
        ];
    }

    /**
     * Get the entry this register row belongs to.
     *
     * @return BelongsTo<EquipmentInspectionEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(EquipmentInspectionEntry::class, 'entry_id');
    }
}
