<?php

namespace App\Models;

use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Place>  $query
     * @return Builder<Place>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsToMany<Thing, $this>
     */
    public function things(): BelongsToMany
    {
        return $this->belongsToMany(Thing::class)
            ->withPivot(['stock_point', 'is_active'])
            ->withTimestamps();
    }

    /**
     * Active things assigned to this place.
     *
     * @return BelongsToMany<Thing, $this>
     */
    public function activeThings(): BelongsToMany
    {
        return $this->things()
            ->where('things.is_active', true)
            ->wherePivot('is_active', true);
    }

    /**
     * @return HasMany<StockCheck, $this>
     */
    public function stockChecks(): HasMany
    {
        return $this->hasMany(StockCheck::class);
    }
}
