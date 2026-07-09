<?php

namespace App\Models;

use App\Enums\SmsStatus;
use Database\Factories\SmsLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    /** @use HasFactory<SmsLogFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'doctor_id',
        'phone',
        'token_number',
        'message',
        'status',
        'provider_response',
        'sent_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SmsStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * The doctor associated with the SMS.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
