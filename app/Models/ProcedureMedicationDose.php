<?php

namespace App\Models;

use App\Enums\ProcedureMedicationDoseStatus;
use Database\Factories\ProcedureMedicationDoseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureMedicationDose extends Model
{
    /** @use HasFactory<ProcedureMedicationDoseFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_medication_id',
        'due_at',
        'status',
        'given_at',
        'given_by',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProcedureMedicationDoseStatus::class,
            'due_at' => 'datetime',
            'given_at' => 'datetime',
        ];
    }

    /**
     * Get the medication this dose belongs to.
     *
     * @return BelongsTo<ProcedureMedication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(ProcedureMedication::class, 'procedure_medication_id');
    }

    /**
     * Get the user who gave this dose.
     *
     * @return BelongsTo<User, $this>
     */
    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }
}
