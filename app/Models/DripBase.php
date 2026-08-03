<?php

namespace App\Models;

use Database\Factories\DripBaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DripBase extends Model
{
    /** @use HasFactory<DripBaseFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'default_volume_ml',
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
            'default_volume_ml' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active drip bases.
     *
     * @param  Builder<DripBase>  $query
     * @return Builder<DripBase>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
