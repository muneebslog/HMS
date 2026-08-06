<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rooms with a patient currently on the ward.
     *
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeOccupied(Builder $query): Builder
    {
        return $query->whereHas('currentAdmission');
    }

    /**
     * Rooms without a patient currently on the ward.
     *
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeFree(Builder $query): Builder
    {
        return $query->whereDoesntHave('currentAdmission');
    }

    /** @return HasMany<Procedure, $this> */
    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class);
    }

    /**
     * The active (on-ward) admission occupying this room, if any.
     *
     * @return HasOne<Procedure, $this>
     */
    public function currentAdmission(): HasOne
    {
        return $this->hasOne(Procedure::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->onWard(),
        );
    }

    /**
     * Whether this room currently has an admitted patient.
     */
    public function isOccupied(): bool
    {
        if ($this->relationLoaded('currentAdmission')) {
            return $this->currentAdmission !== null;
        }

        return $this->currentAdmission()->exists();
    }

    /**
     * Whether this room is free for a new admission.
     */
    public function isFree(): bool
    {
        return ! $this->isOccupied();
    }
}
