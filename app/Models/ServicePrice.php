<?php

namespace App\Models;

use App\Enums\TokenDisplayLayout;
use Database\Factories\ServicePriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePrice extends Model
{
    /** @use HasFactory<ServicePriceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_file_check' => false,
        'display_layout' => TokenDisplayLayout::Board->value,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'doctor_id',
        'price',
        'doctor_share',
        'token_starts_from',
        'is_file_check',
        'display_layout',
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
            'doctor_share' => 'float',
            'token_starts_from' => 'integer',
            'is_file_check' => 'boolean',
            'display_layout' => TokenDisplayLayout::class,
        ];
    }

    /**
     * Get the service for this price.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the doctor for this price.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
