<?php

namespace App\Models;

use App\Enums\StockLocation;
use App\Enums\StockMovementReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'from_location',
        'to_location',
        'quantity',
        'reason',
        'health_aide_id',
        'user_id',
        'reference_type',
        'reference_id',
        'notes',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_location' => StockLocation::class,
            'to_location' => StockLocation::class,
            'reason' => StockMovementReason::class,
            'quantity' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<HealthAide, $this>
     */
    public function healthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
