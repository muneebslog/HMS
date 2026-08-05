<?php

namespace App\Models;

use Database\Factories\ProcedureOperationNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureOperationNote extends Model
{
    /** @use HasFactory<ProcedureOperationNoteFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'operated_on',
        'started_at',
        'ended_at',
        'operation',
        'surgeon',
        'nurse',
        'anaesthesia',
        'findings',
        'procedure_text',
        'closure',
        'drain',
        'biopsy',
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
            'operated_on' => 'date',
        ];
    }

    /**
     * Get the procedure this operation note belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who recorded this operation note.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
