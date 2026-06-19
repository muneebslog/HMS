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
        'time_required',
        'is_in_house',
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
        ];
    }
}
