<?php

namespace App\Models;

use App\Enums\FetalPresentation;
use App\Enums\LiquorVolume;
use App\Enums\PlacentaPosition;
use Carbon\CarbonInterface;
use Database\Factories\GyneUltrasoundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface $scanned_on
 * @property FetalPresentation|null $presentation
 * @property PlacentaPosition|null $placenta
 * @property LiquorVolume|null $liquor
 */
class GyneUltrasound extends Model
{
    /** @use HasFactory<GyneUltrasoundFactory> */
    use HasFactory;

    /**
     * Fetal heart rates outside this range (bpm) are flagged for the doctor.
     */
    public const NORMAL_FHR_MIN = 110;

    public const NORMAL_FHR_MAX = 160;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_token_id',
        'patient_id',
        'scanned_on',
        'fetus_count',
        'ga_weeks',
        'ga_days',
        'fetal_heart_rate',
        'presentation',
        'placenta',
        'liquor',
        'afi',
        'efw_grams',
        'impression',
        'recorded_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scanned_on' => 'date',
            'fetus_count' => 'integer',
            'ga_weeks' => 'integer',
            'ga_days' => 'integer',
            'fetal_heart_rate' => 'integer',
            'presentation' => FetalPresentation::class,
            'placenta' => PlacentaPosition::class,
            'liquor' => LiquorVolume::class,
            'afi' => 'decimal:1',
            'efw_grams' => 'integer',
        ];
    }

    /**
     * Get the queue token (visit) this scan was reported for.
     *
     * @return BelongsTo<QueueToken, $this>
     */
    public function queueToken(): BelongsTo
    {
        return $this->belongsTo(QueueToken::class);
    }

    /**
     * Get the patient this scan belongs to.
     *
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the user who first entered this report.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Get the user who last changed this report.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Gestational age by scan on the scan date, formatted as "14+2 wks".
     */
    public function gestationalAgeLabel(): ?string
    {
        if ($this->ga_weeks === null) {
            return null;
        }

        return __(':weeks+:days wks', ['weeks' => $this->ga_weeks, 'days' => $this->ga_days ?? 0]);
    }

    /**
     * Expected delivery date by scan: scan date plus the days left to 40 weeks.
     */
    public function expectedDeliveryDate(): ?CarbonInterface
    {
        if ($this->ga_weeks === null) {
            return null;
        }

        $gestationDays = ($this->ga_weeks * 7) + ($this->ga_days ?? 0);

        return $this->scanned_on->copy()->addDays(280 - $gestationDays);
    }

    /**
     * Number of fetuses as a word, e.g. "Single" or "Twins".
     */
    public function fetusCountLabel(): ?string
    {
        return match ($this->fetus_count) {
            null => null,
            1 => __('Single'),
            2 => __('Twins'),
            3 => __('Triplets'),
            default => __(':count fetuses', ['count' => $this->fetus_count]),
        };
    }

    /**
     * Whether the fetal heart rate is outside the normal range.
     */
    public function hasAbnormalHeartRate(): bool
    {
        return $this->fetal_heart_rate !== null
            && ($this->fetal_heart_rate < self::NORMAL_FHR_MIN || $this->fetal_heart_rate > self::NORMAL_FHR_MAX);
    }

    /**
     * Short labels for findings that need the doctor's attention.
     *
     * @return list<string>
     */
    public function concernLabels(): array
    {
        return array_values(array_filter([
            $this->hasAbnormalHeartRate() ? __('FHR :rate', ['rate' => $this->fetal_heart_rate]) : null,
            $this->presentation !== null && ! in_array($this->presentation, [FetalPresentation::Cephalic, FetalPresentation::Variable], true)
                ? $this->presentation->label()
                : null,
            $this->placenta?->isConcerning() ? __('Placenta :position', ['position' => $this->placenta->label()]) : null,
            $this->liquor?->isConcerning() ? __('Liquor :volume', ['volume' => $this->liquor->label()]) : null,
        ]));
    }
}
