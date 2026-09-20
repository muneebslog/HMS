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
        'age_low',
        'age_high',
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
            'value_low' => 'float',
            'value_high' => 'float',
            'age_low' => 'integer',
            'age_high' => 'integer',
        ];
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
