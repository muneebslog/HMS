<?php

namespace App\Models;

use App\Enums\TokenResetType;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TokenResetType $token_reset_type
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_standalone',
        'needs_vitals',
        'ends_at_vitals',
        'needs_medication',
        'is_drip',
        'appear_on_er',
        'token_reset_type',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'needs_vitals' => false,
        'ends_at_vitals' => false,
        'needs_medication' => false,
        'is_drip' => false,
        'appear_on_er' => false,
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
            'is_standalone' => 'boolean',
            'needs_vitals' => 'boolean',
            'ends_at_vitals' => 'boolean',
            'needs_medication' => 'boolean',
            'is_drip' => 'boolean',
            'appear_on_er' => 'boolean',
            'token_reset_type' => TokenResetType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active services.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the prices associated with this service.
     */
    public function servicePrices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    /**
     * Get the queues associated with this service.
     *
     * @return HasMany<ServiceQueue, $this>
     */
    public function serviceQueues(): HasMany
    {
        return $this->hasMany(ServiceQueue::class);
    }
}
