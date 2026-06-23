<?php

namespace App\Models;

use App\Enums\TokenResetType;
use Database\Factories\ServiceQueueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TokenResetType $reset_type
 */
class ServiceQueue extends Model
{
    /** @use HasFactory<ServiceQueueFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'doctor_id',
        'shift_id',
        'date',
        'reset_type',
        'opened_at',
        'closed_at',
        'status',
        'last_token_number',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_token_number' => 'integer',
            'reset_type' => TokenResetType::class,
        ];
    }

    /**
     * Get the service for this queue.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the doctor for this queue.
     *
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the shift that opened this queue.
     *
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the tokens issued from this queue.
     *
     * @return HasMany<QueueToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(QueueToken::class);
    }
}
