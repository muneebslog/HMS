<?php

namespace App\Models;

use Database\Factories\ProcedureApparentInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcedureApparentInvoice extends Model
{
    /** @use HasFactory<ProcedureApparentInvoiceFactory> */
    use HasFactory;

    /**
     * Default fee line names shown when creating an apparent invoice.
     *
     * @var list<string>
     */
    public const DEFAULT_FEE_NAMES = [
        'Surgeon Fee',
        'OT Medicine Charges',
        'Room Charges',
        'Anesthesia Fee',
        'Pediatric Fee',
        'Nursing Care',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'total',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'total' => 'float',
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
     * @return HasMany<ProcedureApparentInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProcedureApparentInvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Build empty default fee rows for the editor UI.
     *
     * @return list<array{name: string, amount: string}>
     */
    public static function defaultFeeRows(): array
    {
        return array_map(
            fn (string $name): array => ['name' => $name, 'amount' => ''],
            self::DEFAULT_FEE_NAMES,
        );
    }
}
