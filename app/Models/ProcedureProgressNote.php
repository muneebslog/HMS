<?php

namespace App\Models;

use Database\Factories\ProcedureProgressNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureProgressNote extends Model
{
    /** @use HasFactory<ProcedureProgressNoteFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'noted_at',
        'note',
        'doctor_user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'noted_at' => 'datetime',
        ];
    }

    /**
     * Get the procedure this progress note belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the doctor user who wrote this progress note.
     *
     * @return BelongsTo<User, $this>
     */
    public function doctorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }
}
