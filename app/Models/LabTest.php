<?php

namespace App\Models;

use Database\Factories\LabTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'test_code',
        'test_price',
        'sample',
        'time_required',
        'is_in_house',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
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
            'test_price' => 'float',
            'is_in_house' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active lab tests.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
