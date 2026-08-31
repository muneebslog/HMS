<?php

namespace App\Models;

use Database\Factories\DutyLocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DutyLocation extends Model
{
    /** @use HasFactory<DutyLocationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<DutyLocation>  $query
     * @return Builder<DutyLocation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<DutyAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }
}
