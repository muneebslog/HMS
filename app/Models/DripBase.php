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
        'show_on_er',
        'is_active',
        'stock_quantity',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'show_on_er' => false,
        'is_active' => true,
        'stock_quantity' => 0,
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
            'show_on_er' => 'boolean',
            'is_active' => 'boolean',
            'stock_quantity' => 'integer',
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
