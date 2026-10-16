<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ShiftTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftTemplate extends Model
{
    /** @use HasFactory<ShiftTemplateFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
        'color',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Scope the query to active templates in display order.
     *
     * @param  Builder<ShiftTemplate>  $query
     * @return Builder<ShiftTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('starts_at');
    }

    /**
     * Whether the shift ends on the next calendar day.
     */
    public function crossesMidnight(): bool
    {
        return $this->ends_at <= $this->starts_at;
    }

    /**
     * The start and end of this shift when it starts on the given date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function occurrenceOn(CarbonInterface $date): array
    {
        $day = CarbonImmutable::parse($date->toDateString());
        $start = $day->setTimeFromTimeString($this->starts_at);
        $end = $day->setTimeFromTimeString($this->ends_at);

        if ($end->lte($start)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * Short label with timings, e.g. "Night 22:00–08:00".
     */
    public function label(): string
    {
        return $this->name.' '.substr($this->starts_at, 0, 5).'–'.substr($this->ends_at, 0, 5);
    }

    /**
     * @return HasMany<AttendanceDuty, $this>
     */
    public function duties(): HasMany
    {
        return $this->hasMany(AttendanceDuty::class);
    }
}
