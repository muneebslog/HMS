<?php

namespace App\Models;

use Database\Factories\LabInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabInvoiceItem extends Model
{
    /** @use HasFactory<LabInvoiceItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lab_invoice_id',
        'lab_test_id',
        'test_name',
        'test_code',
        'time_required',
        'is_in_house',
        'price',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_in_house' => 'boolean',
        ];
    }

    /**
     * Get the lab invoice for this item.
     */
    public function labInvoice(): BelongsTo
    {
        return $this->belongsTo(LabInvoice::class);
    }

    /**
     * Get the lab test for this item.
     */
    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class);
    }
}
