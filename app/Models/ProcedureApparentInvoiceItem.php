<?php

namespace App\Models;

use Database\Factories\ProcedureApparentInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureApparentInvoiceItem extends Model
{
    /** @use HasFactory<ProcedureApparentInvoiceItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_apparent_invoice_id',
        'name',
        'amount',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProcedureApparentInvoice, $this>
     */
    public function apparentInvoice(): BelongsTo
    {
        return $this->belongsTo(ProcedureApparentInvoice::class, 'procedure_apparent_invoice_id');
    }
}
