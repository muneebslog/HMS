<?php

namespace App\Models;

use App\Enums\ProcedureDocumentKind;
use Database\Factories\ProcedureDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureDocument extends Model
{
    /** @use HasFactory<ProcedureDocumentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'kind',
        'generated_at',
        'generated_by',
        'printed_at',
        'printed_by',
        'path',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProcedureDocumentKind::class,
            'generated_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * Get the procedure this document belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who generated this document.
     *
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Get the user who printed this document.
     *
     * @return BelongsTo<User, $this>
     */
    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
