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
        'volume_ml',
        'name',
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
        ];
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
}
