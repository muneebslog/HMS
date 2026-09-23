<?php

namespace App\Models;

use App\Enums\LabFieldType;
use Database\Factories\LabFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabField extends Model
{
    /** @use HasFactory<LabFieldFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'unit',
        'type',
        'options',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'numeric',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LabFieldType::class,
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active lab fields.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the normal ranges defined for this field.
     *
     * @return HasMany<LabFieldRange, $this>
     */
    public function ranges(): HasMany
    {
        return $this->hasMany(LabFieldRange::class);
    }

    /**
     * Get the lab tests this field is attached to.
     *
     * @return BelongsToMany<LabTest, $this>
     */
    public function labTests(): BelongsToMany
    {
        return $this->belongsToMany(LabTest::class, 'lab_test_field')
            ->withPivot('display_order')
            ->withTimestamps();
    }
}
