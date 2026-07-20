<?php

namespace App\Models;

use Database\Factories\LabInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LabInvoice extends Model
{
    /** @use HasFactory<LabInvoiceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'invoice_number',
        'subtotal',
        'discount_percentage',
        'discount_amount',
        'total',
        'status',
        'created_by',
        'shift_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discount_percentage' => 'float',
            'discount_amount' => 'float',
            'total' => 'float',
        ];
    }

    /**
     * Get the patient for this lab invoice.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the user who created this lab invoice.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the items associated with this lab invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(LabInvoiceItem::class);
    }

    /**
     * Get the shift this lab invoice belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the latest lab API log for this invoice.
     */
    public function labApiLog(): HasOne
    {
        return $this->hasOne(LabApiLog::class)->latestOfMany();
    }

    /**
     * Generate a unique lab invoice number.
     *
     * Format: auto-incrementing number starting at 8001.
     */
    public static function generateNumber(): string
    {
        $sequence = LabInvoiceNumberSequence::firstOrCreate(
            ['date' => '2000-01-01'],
            ['last_number' => 928000]
        );

        $sequence->increment('last_number');
        $sequence->refresh();

        return (string) $sequence->last_number;
    }
}
