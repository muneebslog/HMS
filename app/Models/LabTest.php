<?php

namespace App\Models;

use App\Enums\LabReportLayout;
use Database\Factories\LabTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LabTest extends Model
{
    /** @use HasFactory<LabTestFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'test_name',
        'display_name',
        'test_code',
        'test_price',
        'sample',
        'time_required',
        'is_in_house',
        'is_active',
        'report_layout',
        'report_note',
        'report_show_ranges',
        'report_custom_template',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'report_show_ranges' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'test_price' => 'float',
            'is_in_house' => 'boolean',
            'is_active' => 'boolean',
            'report_layout' => LabReportLayout::class,
            'report_show_ranges' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active lab tests.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the fields (parameters) that make up this test, in display order.
     *
     * @return BelongsToMany<LabField, $this>
     */
    public function fields(): BelongsToMany
    {
        return $this->belongsToMany(LabField::class, 'lab_test_field')
            ->withPivot('display_order', 'section')
            ->withTimestamps()
            ->orderByPivot('display_order');
    }

    /**
     * Get the heading printed on the report: the display name when set,
     * otherwise the test name (e.g. "CBC" → "Complete Blood Count").
     */
    public function reportTitle(): string
    {
        return trim(filled($this->display_name) ? $this->display_name : $this->test_name);
    }

    /**
     * Get the layout used on the report: the chosen one, or Compact for
     * single-field tests and Table for everything else.
     */
    public function resolvedReportLayout(?int $fieldCount = null): LabReportLayout
    {
        if ($this->report_layout !== null) {
            return $this->report_layout;
        }

        $fieldCount ??= $this->relationLoaded('fields') ? $this->fields->count() : $this->fields()->count();

        return $fieldCount === 1 ? LabReportLayout::Compact : LabReportLayout::Table;
    }
}
