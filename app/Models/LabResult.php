<?php

namespace App\Models;

use Database\Factories\LabResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabResult extends Model
{
    /** @use HasFactory<LabResultFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lab_invoice_item_id',
        'lab_field_id',
        'value',
        'entered_by',
    ];

    /**
     * Get the ordered test (invoice item) this result belongs to.
     *
     * @return BelongsTo<LabInvoiceItem, $this>
     */
    public function labInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(LabInvoiceItem::class);
    }

    /**
     * Get the field (parameter) this result is for.
     *
     * @return BelongsTo<LabField, $this>
     */
    public function labField(): BelongsTo
    {
        return $this->belongsTo(LabField::class);
    }

    /**
     * Get the user who entered this result.
     *
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
