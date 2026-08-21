<?php

namespace App\Models;

use Database\Factories\StockCheckItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCheckItem extends Model
{
    /** @use HasFactory<StockCheckItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'stock_check_id',
        'thing_id',
        'stock_point',
        'counted_quantity',
        'refill_needed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_point' => 'integer',
            'counted_quantity' => 'integer',
            'refill_needed' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockCheck, $this>
     */
    public function stockCheck(): BelongsTo
    {
        return $this->belongsTo(StockCheck::class);
    }

    /**
     * @return BelongsTo<Thing, $this>
     */
    public function thing(): BelongsTo
    {
        return $this->belongsTo(Thing::class);
    }
}
