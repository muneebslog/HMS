<?php

namespace App\Livewire\Concerns;

use App\Enums\GyneOperation;
use App\Enums\GyneProblem;
use App\Models\GyneHistory;
use App\Models\QueueToken;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

trait InteractsWithGyneHistory
{
    public ?int $gravida = null;

    public ?int $para = null;

    public ?int $livingChildren = null;

    public ?int $miscarriages = null;

    /**
     * Month and year as "YYYY-MM".
     */
    public string $marriedSince = '';

    /**
     * Month and year as "YYYY-MM".
     */
    public string $lastPregnancyAt = '';

    /**
     * Date as "YYYY-MM-DD".
     */
    public string $lmp = '';

    /**
     * @var list<string>
     */
    public array $problems = [];

    public string $problemsOther = '';

    /**
     * @var list<string>
     */
    public array $operations = [];

    public string $operationsOther = '';

    /**
     * Arrived (waiting or serving) tokens in gynecologist queues for the latest shift.
     *
     * Pass a doctor id to limit the list to that doctor's queues.
     *
     * @return Collection<int, QueueToken>
     */
    protected function gyneOpdTokens(?int $doctorId = null): Collection
    {
        $shift = Shift::current() ?? Shift::query()->latest('opened_at')->first();

        if ($shift === null) {
            return new Collection;
        }

        return QueueToken::query()
            ->with(['patient.family', 'serviceQueue.service', 'serviceQueue.doctor', 'vital', 'gyneHistory', 'gyneUltrasound.recordedBy'])
            ->whereNotNull('arrived_at')
            ->whereIn('status', ['waiting', 'serving'])
            ->whereHas('serviceQueue', function (Builder $queueQuery) use ($shift, $doctorId): void {
                $queueQuery->forShift($shift)
                    ->when(
                        $doctorId !== null,
                        fn (Builder $query) => $query->where('doctor_id', $doctorId),
                        fn (Builder $query) => $query->whereHas('doctor', fn (Builder $doctorQuery) => $doctorQuery->gynecologists()),
                    );
            })
            ->orderBy('arrived_at')
            ->orderBy('token_number')
            ->get();
    }

    /**
     * Fill the form from this visit's history, or else from the patient's most recent one.
     */
    protected function fillGyneHistoryForm(QueueToken $token): void
    {
        $this->resetGyneHistoryForm();

        $history = $token->gyneHistory
            ?? GyneHistory::query()
                ->where('patient_id', $token->patient_id)
                ->latest('id')
                ->first();

        if ($history === null) {
            return;
        }

        $this->gravida = $history->gravida;
        $this->para = $history->para;
        $this->livingChildren = $history->living_children;
        $this->miscarriages = $history->miscarriages;
        $this->marriedSince = $history->married_since?->format('Y-m') ?? '';
        $this->lastPregnancyAt = $history->last_pregnancy_at?->format('Y-m') ?? '';
        $this->lmp = $history->lmp?->toDateString() ?? '';
        $this->problems = array_values($history->problems ?? []);
        $this->problemsOther = $history->problems_other ?? '';
        $this->operations = array_values($history->operations ?? []);
        $this->operationsOther = $history->operations_other ?? '';
    }

    /**
     * Clear every history form field.
     */
    protected function resetGyneHistoryForm(): void
    {
        $this->reset([
            'gravida', 'para', 'livingChildren', 'miscarriages',
            'marriedSince', 'lastPregnancyAt', 'lmp',
            'problems', 'problemsOther', 'operations', 'operationsOther',
        ]);
        $this->resetValidation();
    }

    /**
     * Validate the form and save it as this visit's history.
     */
    protected function saveGyneHistory(QueueToken $token): GyneHistory
    {
        $validated = $this->validate([
            'gravida' => ['nullable', 'integer', 'min:0', 'max:30'],
            'para' => ['nullable', 'integer', 'min:0', 'max:30'],
            'livingChildren' => ['nullable', 'integer', 'min:0', 'max:30'],
            'miscarriages' => ['nullable', 'integer', 'min:0', 'max:30'],
            'marriedSince' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'lastPregnancyAt' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'lmp' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'problems' => ['array'],
            'problems.*' => [Rule::in(GyneProblem::values())],
            'problemsOther' => ['nullable', 'string', 'max:500'],
            'operations' => ['array'],
            'operations.*' => [Rule::in(GyneOperation::values())],
            'operationsOther' => ['nullable', 'string', 'max:500'],
        ]);

        $attributes = [
            'patient_id' => $token->patient_id,
            'gravida' => $validated['gravida'],
            'para' => $validated['para'],
            'living_children' => $validated['livingChildren'],
            'miscarriages' => $validated['miscarriages'],
            'married_since' => $this->monthToDate($validated['marriedSince']),
            'last_pregnancy_at' => $this->monthToDate($validated['lastPregnancyAt']),
            'lmp' => filled($validated['lmp']) ? $validated['lmp'] : null,
            'problems' => array_values(array_unique($validated['problems'])),
            'problems_other' => filled($validated['problemsOther']) ? trim($validated['problemsOther']) : null,
            'operations' => array_values(array_unique($validated['operations'])),
            'operations_other' => filled($validated['operationsOther']) ? trim($validated['operationsOther']) : null,
        ];

        $history = GyneHistory::query()->firstOrNew(['queue_token_id' => $token->id]);

        if ($history->exists) {
            $attributes['updated_by'] = auth()->id();
        } else {
            $attributes['recorded_by'] = auth()->id();
        }

        $history->fill($attributes)->save();

        return $history;
    }

    /**
     * Live gestational age preview for the LMP being entered, e.g. "14+2 wks · EDD 12 Jan 2027".
     */
    public function lmpPreview(): ?string
    {
        if (blank($this->lmp)) {
            return null;
        }

        try {
            $history = new GyneHistory(['lmp' => Carbon::createFromFormat('Y-m-d', $this->lmp)]);
        } catch (\Throwable) {
            return null;
        }

        $label = $history->gestationalAgeLabel();

        if ($label === null) {
            return null;
        }

        return __(':age · EDD :edd', [
            'age' => $label,
            'edd' => $history->expectedDeliveryDate()->format('d M Y'),
        ]);
    }

    /**
     * Convert a "YYYY-MM" month into the first day of that month.
     */
    private function monthToDate(?string $month): ?string
    {
        return filled($month) ? $month.'-01' : null;
    }
}
