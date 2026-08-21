<?php

namespace App\Models;

use Database\Factories\ThingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Thing extends Model
{
    /** @use HasFactory<ThingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'unit',
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
     * @param  Builder<Thing>  $query
     * @return Builder<Thing>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsToMany<Place, $this>
     */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class)
            ->withPivot(['stock_point', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<StockCheckItem, $this>
     */
    public function stockCheckItems(): HasMany
    {
        return $this->hasMany(StockCheckItem::class);
    }
}
