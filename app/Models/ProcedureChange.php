<?php

namespace App\Models;

use Database\Factories\ProcedureChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureChange extends Model
{
    /** @use HasFactory<ProcedureChangeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'from_procedure_type_id',
        'to_procedure_type_id',
        'from_name',
        'to_name',
        'from_amount',
        'to_amount',
        'package_price',
        'discount_amount',
        'changed_by',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'discount_amount' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_amount' => 'float',
            'to_amount' => 'float',
            'package_price' => 'float',
            'discount_amount' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * @return BelongsTo<ProcedureType, $this>
     */
    public function fromProcedureType(): BelongsTo
    {
        return $this->belongsTo(ProcedureType::class, 'from_procedure_type_id');
    }

    /**
     * @return BelongsTo<ProcedureType, $this>
     */
    public function toProcedureType(): BelongsTo
    {
        return $this->belongsTo(ProcedureType::class, 'to_procedure_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
