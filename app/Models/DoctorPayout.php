<?php

namespace App\Models;

use Database\Factories\DoctorPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorPayout extends Model
{
    /** @use HasFactory<DoctorPayoutFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'doctor_id',
        'date',
        'from_date',
        'to_date',
        'total_amount',
        'share_amount',
        'paid_at',
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
            'date' => 'date',
            'from_date' => 'date',
            'to_date' => 'date',
            'total_amount' => 'float',
            'share_amount' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Get the doctor for this payout.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the user who recorded this payout.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the shift during which this payout was recorded.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
