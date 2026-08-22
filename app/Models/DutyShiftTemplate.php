<?php

namespace App\Models;

use Database\Factories\DutyShiftTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class DutyShiftTemplate extends Model
{
    /** @use HasFactory<DutyShiftTemplateFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'grace_minutes_in',
        'grace_minutes_out',
        'break_minutes',
        'station',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DutyAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }

    /**
     * Build duty start/end datetimes for a calendar date.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon}
     */
    public function windowForDate(Carbon $date): array
    {
        $startsAt = $date->copy()->setTimeFromTimeString($this->start_time->format('H:i:s'));
        $endsAt = $date->copy()->setTimeFromTimeString($this->end_time->format('H:i:s'));

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt->addDay();
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
