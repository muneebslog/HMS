<?php

namespace App\Models;

use Database\Factories\ProcedureVitalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureVital extends Model
{
    /** @use HasFactory<ProcedureVitalFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'recorded_at',
        'pulse',
        'bp_systolic',
        'bp_diastolic',
        'resp_rate',
        'temp',
        'cvp',
        'iv_fluid',
        'oral_ng',
        'urine',
        'stool',
        'aspirate',
        'drain',
        'notes',
        'recorded_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'temp' => 'float',
        ];
    }

    /**
     * Get the procedure this vital record belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who recorded this vital.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
