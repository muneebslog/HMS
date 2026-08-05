<?php

namespace App\Models;

use Database\Factories\ProcedureDischargeDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureDischargeDetail extends Model
{
    /** @use HasFactory<ProcedureDischargeDetailFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'blood_group',
        'indication',
        'procedure_time',
        'parity',
        'baby_sex',
        'baby_weight',
        'baby_condition',
        'rx_text',
        'stitch_removal_date',
        'outcome_summary',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'procedure_time' => 'datetime',
            'stitch_removal_date' => 'date',
        ];
    }

    /**
     * Get the procedure this discharge detail belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
