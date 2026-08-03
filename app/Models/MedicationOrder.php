<?php

namespace App\Models;

use App\Enums\MedicationOrderStatus;
use Database\Factories\MedicationOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MedicationOrderStatus $status
 */
class MedicationOrder extends Model
{
    /** @use HasFactory<MedicationOrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_token_id',
        'patient_id',
        'doctor_id',
        'prescribed_by',
        'status',
        'notes',
        'administered_by',
        'administered_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MedicationOrderStatus::class,
            'administered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QueueToken, $this>
     */
    public function queueToken(): BelongsTo
    {
        return $this->belongsTo(QueueToken::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function prescribedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    /**
     * @return HasMany<MedicationOrderMedicine, $this>
     */
    public function medicines(): HasMany
    {
        return $this->hasMany(MedicationOrderMedicine::class);
    }

    /**
     * @return HasMany<MedicationOrderInjection, $this>
     */
    public function injections(): HasMany
    {
        return $this->hasMany(MedicationOrderInjection::class);
    }

    /**
     * @return HasMany<MedicationOrderDrip, $this>
     */
    public function drips(): HasMany
    {
        return $this->hasMany(MedicationOrderDrip::class);
    }
}
