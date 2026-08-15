<?php

namespace App\Models;

use App\Enums\InjectionAdministrationType;
use Database\Factories\InjectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Injection extends Model
{
    /** @use HasFactory<InjectionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_form',
        'default_administration_type',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_administration_type' => 'im',
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
            'default_administration_type' => InjectionAdministrationType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active injections.
     *
     * @param  Builder<Injection>  $query
     * @return Builder<Injection>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
