<?php

namespace App\Models;

use Database\Factories\ProcedurePreOpOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedurePreOpOrder extends Model
{
    /** @use HasFactory<ProcedurePreOpOrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'give_bath',
        'provide_hospital_dress',
        'npo_from',
        'mark_operation_site',
        'shave_and_prepare',
        'blood_pints',
        'investigations',
        'pre_medication',
        'send_to_ot_at',
        'other_orders',
        'operation_site',
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
            'give_bath' => 'boolean',
            'provide_hospital_dress' => 'boolean',
            'npo_from' => 'datetime',
            'mark_operation_site' => 'boolean',
            'shave_and_prepare' => 'boolean',
            'send_to_ot_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the procedure this pre-op order belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who completed this pre-op order.
     *
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
