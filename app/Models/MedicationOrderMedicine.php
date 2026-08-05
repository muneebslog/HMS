<?php

namespace App\Models;

use App\Enums\MedicineDose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property MedicineDose $dose
 */
class MedicationOrderMedicine extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'medication_order_id',
        'medicine_id',
        'dose',
        'days',
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
            'dose' => MedicineDose::class,
            'days' => 'integer',
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
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function deliveredByHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'delivered_by_health_aide_id');
    }
}
