<?php

namespace App\Models;

use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    /** @use HasFactory<DoctorFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'specialization',
        'payout_daily',
        'get_full_slips',
        'full_slips_count',
        'duty_start_time',
        'user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payout_daily' => 'boolean',
            'get_full_slips' => 'boolean',
            'full_slips_count' => 'integer',
            'duty_start_time' => 'datetime:H:i',
        ];
    }

    /**
     * Get the portal user linked to this doctor profile.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the prices associated with this doctor.
     */
    public function servicePrices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    /**
     * Get the queues associated with this doctor.
     */
    public function serviceQueues(): HasMany
    {
        return $this->hasMany(ServiceQueue::class);
    }

    /**
     * Get the payouts associated with this doctor.
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(DoctorPayout::class);
    }

    /**
     * Calculate the share amount for a collection of invoice items.
     *
     * When get_full_slips is enabled, the first N items (or first N per day)
     * are paid at the full price; remaining items use the configured share.
     *
     * @param  Collection<int, InvoiceItem>  $items
     */
    public function calculateShareAmount(Collection $items, bool $perDay = false): float
    {
        if (! $this->get_full_slips || $this->full_slips_count <= 0) {
            return $items->sum(
                fn (InvoiceItem $item) => $item->price * ($item->doctor_share ?? 0) / 100
            );
        }

        $dayCounts = [];
        $count = 0;

        return $items->sum(function (InvoiceItem $item) use (&$dayCounts, &$count, $perDay) {
            if ($perDay) {
                $dateKey = $item->created_at->toDateString();
                $count = $dayCounts[$dateKey] = ($dayCounts[$dateKey] ?? 0) + 1;
            } else {
                $count++;
            }

            return $count <= $this->full_slips_count
                ? $item->price
                : $item->price * ($item->doctor_share ?? 0) / 100;
        });
    }

    /**
     * Calculate the share amount for each invoice item in order.
     *
     * @param  Collection<int, InvoiceItem>  $items
     * @return array<int, float>
     */
    public function calculateItemShareAmounts(Collection $items, bool $perDay = false): array
    {
        if (! $this->get_full_slips || $this->full_slips_count <= 0) {
            return $items->mapWithKeys(
                fn (InvoiceItem $item) => [$item->id => $item->price * ($item->doctor_share ?? 0) / 100]
            )->all();
        }

        $amounts = [];
        $dayCounts = [];

        foreach ($items as $item) {
            if ($perDay) {
                $dateKey = $item->created_at->toDateString();
                $count = $dayCounts[$dateKey] = ($dayCounts[$dateKey] ?? 0) + 1;
            } else {
                $count = count($amounts) + 1;
            }

            $amounts[$item->id] = $count <= $this->full_slips_count
                ? $item->price
                : $item->price * ($item->doctor_share ?? 0) / 100;
        }

        return $amounts;
    }
}
