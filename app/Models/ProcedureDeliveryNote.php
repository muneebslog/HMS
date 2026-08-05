<?php

namespace App\Models;

use Database\Factories\ProcedureDeliveryNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureDeliveryNote extends Model
{
    /** @use HasFactory<ProcedureDeliveryNoteFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'labour_type',
        'procedure_name',
        'obstetrician',
        'assistant',
        'delivered_at',
        'analgesia',
        'delivery_details',
        'labour_first_stage',
        'labour_second_stage',
        'labour_third_stage',
        'complications',
        'baby_sex',
        'baby_weight',
        'apgar_score',
        'resuscitated_by',
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
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Get the procedure this delivery note belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who recorded this delivery note.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
