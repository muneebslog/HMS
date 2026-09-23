<?php

namespace App\Models;

use App\Enums\LabFieldRangeCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabFieldRange extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lab_field_id',
        'category',
        'value_low',
        'value_high',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => LabFieldRangeCategory::class,
        ];
    }

    /**
     * Format the range for display, e.g. "12–16" or "Male ≥ 4".
     */
    public function formatted(): string
    {
        $bounds = match (true) {
            $this->value_low !== null && $this->value_high !== null => "{$this->value_low}–{$this->value_high}",
            $this->value_low !== null => __('≥ :value', ['value' => $this->value_low]),
            $this->value_high !== null => __('≤ :value', ['value' => $this->value_high]),
            default => __('no range set'),
        };

        if ($this->category === LabFieldRangeCategory::General) {
            return $bounds;
        }

        return $this->category->label().' '.$bounds;
    }

    /**
     * Get the field this range belongs to.
     *
     * @return BelongsTo<LabField, $this>
     */
    public function labField(): BelongsTo
    {
        return $this->belongsTo(LabField::class);
    }
}
