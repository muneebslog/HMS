<?php

namespace App\Models;

use App\Models\Concerns\HasStockBalances;
use Database\Factories\SupplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supply extends Model
{
    /** @use HasFactory<SupplyFactory> */
    use HasFactory, HasStockBalances;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_form',
        'category',
        'unit',
        'default_par',
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
            'default_par' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active supplies.
     *
     * @param  Builder<Supply>  $query
     * @return Builder<Supply>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
