<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationOrderDripAdditive extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'medication_order_drip_id',
        'injection_id',
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
     * @return BelongsTo<MedicationOrderDrip, $this>
     */
    public function drip(): BelongsTo
    {
        return $this->belongsTo(MedicationOrderDrip::class, 'medication_order_drip_id');
    }

    /**
     * @return BelongsTo<Injection, $this>
     */
    public function injection(): BelongsTo
    {
        return $this->belongsTo(Injection::class);
    }
}
