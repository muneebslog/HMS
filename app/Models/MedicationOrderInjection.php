<?php

namespace App\Models;

use App\Enums\InjectionAdministrationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property InjectionAdministrationType $administration_type
 */
class MedicationOrderInjection extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'medication_order_id',
        'injection_id',
        'administration_type',
        'comment',
        'volume_ml',
        'name',
        'delivered_at',
        'delivered_by_health_aide_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'administration_type' => InjectionAdministrationType::class,
            'volume_ml' => 'float',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Whether this line has been delivered.
     */
    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    /**
     * @return BelongsTo<MedicationOrder, $this>
     */
    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class);
    }

    /**
     * @return BelongsTo<Injection, $this>
     */
    public function injection(): BelongsTo
    {
        return $this->belongsTo(Injection::class);
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function deliveredByHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'delivered_by_health_aide_id');
    }
}
