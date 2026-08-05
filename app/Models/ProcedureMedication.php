<?php

namespace App\Models;

use App\Enums\ProcedureMedicationForm;
use App\Enums\ProcedureMedicationScheduleType;
use Database\Factories\ProcedureMedicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcedureMedication extends Model
{
    /** @use HasFactory<ProcedureMedicationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'form',
        'medicine_id',
        'injection_id',
        'drip_base_id',
        'custom_name',
        'dose',
        'route',
        'notes',
        'schedule_type',
        'schedule_times',
        'interval_hours',
        'starts_at',
        'ends_at',
        'status',
        'prescribed_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'form' => ProcedureMedicationForm::class,
            'schedule_type' => ProcedureMedicationScheduleType::class,
            'schedule_times' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Get the procedure this medication belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the doses scheduled for this medication.
     *
     * @return HasMany<ProcedureMedicationDose, $this>
     */
    public function doses(): HasMany
    {
        return $this->hasMany(ProcedureMedicationDose::class);
    }

    /**
     * Get the catalog medicine for this medication, if any.
     *
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /**
     * Get the catalog injection for this medication, if any.
     *
     * @return BelongsTo<Injection, $this>
     */
    public function injection(): BelongsTo
    {
        return $this->belongsTo(Injection::class);
    }

    /**
     * Get the catalog drip base for this medication, if any.
     *
     * @return BelongsTo<DripBase, $this>
     */
    public function dripBase(): BelongsTo
    {
        return $this->belongsTo(DripBase::class);
    }

    /**
     * Get the user who prescribed this medication.
     *
     * @return BelongsTo<User, $this>
     */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by');
    }

    /**
     * Get the display name for this medication, preferring the custom name
     * over the related catalog entry's name.
     */
    public function displayName(): string
    {
        return $this->custom_name
            ?? $this->medicine?->name
            ?? $this->injection?->name
            ?? $this->dripBase?->name
            ?? __('Unknown');
    }
}
