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
        'token_reset_type',
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
            'token_reset_type' => TokenResetType::class,
        ];
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
