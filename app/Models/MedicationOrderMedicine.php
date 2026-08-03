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
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
