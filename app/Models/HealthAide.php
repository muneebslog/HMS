<?php

namespace App\Models;

use Database\Factories\HealthAideFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class HealthAide extends Model
{
    /** @use HasFactory<HealthAideFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'pin',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pin',
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
            'pin' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active health aides.
     *
     * @param  Builder<HealthAide>  $query
     * @return Builder<HealthAide>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether the plain PIN is already used by another active health aide.
     */
    public static function pinIsTaken(string $plainPin, ?int $ignoreId = null): bool
    {
        $query = static::query()->active();

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        foreach ($query->get() as $aide) {
            if (Hash::check($plainPin, $aide->pin)) {
                return true;
            }
        }

        return false;
    }
}
