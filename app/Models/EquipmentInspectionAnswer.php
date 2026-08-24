<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entry_id
 * @property string $section
 * @property string $item_key
 * @property bool|null $present
 * @property bool|null $functional
 * @property bool|null $clean
 * @property bool|null $maint_req
 * @property bool|null $checked
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property EquipmentInspectionEntry $entry
 */
class EquipmentInspectionAnswer extends Model
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
        'present',
        'functional',
        'clean',
        'maint_req',
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
            'present' => 'boolean',
            'functional' => 'boolean',
            'clean' => 'boolean',
            'maint_req' => 'boolean',
            'checked' => 'boolean',
        ];
    }

    /**
     * Get the entry this answer belongs to.
     *
     * @return BelongsTo<EquipmentInspectionEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(EquipmentInspectionEntry::class, 'entry_id');
    }

    /**
     * Determine whether this answer represents a fault.
     */
    public function isFault(): bool
    {
        if ($this->present === false || $this->functional === false || $this->maint_req === true) {
            return true;
        }

        return $this->checked === false;
    }

    /**
     * Whether this row is an equipment multi-column answer.
     */
    public function isEquipmentRow(): bool
    {
        return $this->present !== null
            || $this->functional !== null
            || $this->clean !== null
            || $this->maint_req !== null;
    }
}
