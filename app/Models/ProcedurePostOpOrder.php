<?php

namespace App\Models;

use Database\Factories\ProcedurePostOpOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedurePostOpOrder extends Model
{
    /** @use HasFactory<ProcedurePostOpOrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'maintain_intake_output',
        'npo_till',
        'antibiotics',
        'iv_fluids',
        'analgesics',
        'antiemetics',
        'biopsy',
        'others',
        'done_by',
        'completed_by',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'maintain_intake_output' => 'boolean',
            'npo_till' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the procedure this post-op order belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who completed this post-op order.
     *
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
