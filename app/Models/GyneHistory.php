<?php

namespace App\Models;

use App\Enums\GyneOperation;
use App\Enums\GyneProblem;
use Carbon\CarbonInterface;
use Database\Factories\GyneHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface|null $married_since
 * @property CarbonInterface|null $last_pregnancy_at
 * @property CarbonInterface|null $lmp
 * @property list<string>|null $problems
 * @property list<string>|null $operations
 */
class GyneHistory extends Model
{
    /** @use HasFactory<GyneHistoryFactory> */
    use HasFactory;

    /**
     * Pregnancies past this many days from LMP are not treated as ongoing.
     */
    public const MAX_GESTATION_DAYS = 300;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_token_id',
        'patient_id',
        'gravida',
        'para',
        'living_children',
        'miscarriages',
        'married_since',
        'last_pregnancy_at',
        'lmp',
        'problems',
        'problems_other',
        'operations',
        'operations_other',
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
            'gravida' => 'integer',
            'para' => 'integer',
            'living_children' => 'integer',
            'miscarriages' => 'integer',
            'married_since' => 'date',
            'last_pregnancy_at' => 'date',
            'lmp' => 'date',
            'problems' => 'array',
            'operations' => 'array',
        ];
    }

    /**
     * Get the queue token (visit) this history was taken for.
     *
     * @return BelongsTo<QueueToken, $this>
     */
    public function queueToken(): BelongsTo
    {
        return $this->belongsTo(QueueToken::class);
    }

    /**
     * Get the patient this history belongs to.
     *
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the user who first recorded this history.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Get the user who last changed this history.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Gestational age from LMP as of the given date, or null when there is no ongoing pregnancy.
     *
     * @return array{weeks: int, days: int}|null
     */
    public function gestationalAge(?CarbonInterface $asOf = null): ?array
    {
        if ($this->lmp === null) {
            return null;
        }

        $totalDays = (int) $this->lmp->copy()->startOfDay()->diffInDays(($asOf ?? now())->copy()->startOfDay(), false);

        if ($totalDays < 0 || $totalDays > self::MAX_GESTATION_DAYS) {
            return null;
        }

        return ['weeks' => intdiv($totalDays, 7), 'days' => $totalDays % 7];
    }

    /**
     * Gestational age formatted as "14+2 wks".
     */
    public function gestationalAgeLabel(?CarbonInterface $asOf = null): ?string
    {
        $age = $this->gestationalAge($asOf);

        return $age === null ? null : __(':weeks+:days wks', $age);
    }

    /**
     * Expected delivery date (Naegele's rule: LMP + 280 days) while a pregnancy is ongoing.
     */
    public function expectedDeliveryDate(?CarbonInterface $asOf = null): ?CarbonInterface
    {
        if ($this->gestationalAge($asOf) === null) {
            return null;
        }

        return $this->lmp->copy()->addDays(280);
    }

    /**
     * Obstetric summary such as "G3 P2 A1 L2".
     */
    public function obstetricSummary(): ?string
    {
        $parts = array_filter([
            $this->gravida !== null ? 'G'.$this->gravida : null,
            $this->para !== null ? 'P'.$this->para : null,
            $this->miscarriages !== null ? 'A'.$this->miscarriages : null,
            $this->living_children !== null ? 'L'.$this->living_children : null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Labels for the ticked problems plus any free-text problem.
     *
     * @return list<string>
     */
    public function problemLabels(): array
    {
        $labels = collect($this->problems ?? [])
            ->map(fn (string $value) => GyneProblem::tryFrom($value)?->label())
            ->filter()
            ->values()
            ->all();

        return filled($this->problems_other) ? [...$labels, $this->problems_other] : $labels;
    }

    /**
     * Labels for the ticked previous operations plus any free-text operation.
     *
     * @return list<string>
     */
    public function operationLabels(): array
    {
        $labels = collect($this->operations ?? [])
            ->map(fn (string $value) => GyneOperation::tryFrom($value)?->label())
            ->filter()
            ->values()
            ->all();

        return filled($this->operations_other) ? [...$labels, $this->operations_other] : $labels;
    }

    /**
     * Whether the patient has had a previous C-section.
     */
    public function hadCSection(): bool
    {
        return in_array(GyneOperation::CSection->value, $this->operations ?? [], true);
    }
}
