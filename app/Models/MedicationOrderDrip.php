<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationOrderDrip extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'medication_order_id',
        'drip_base_id',
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
     * @return BelongsTo<DripBase, $this>
     */
    public function dripBase(): BelongsTo
    {
        return $this->belongsTo(DripBase::class);
    }

    /**
     * @return HasMany<MedicationOrderDripAdditive, $this>
     */
    public function additives(): HasMany
    {
        return $this->hasMany(MedicationOrderDripAdditive::class);
    }
}
