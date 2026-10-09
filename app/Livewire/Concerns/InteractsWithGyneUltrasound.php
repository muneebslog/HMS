<?php

namespace App\Livewire\Concerns;

use App\Enums\FetalPresentation;
use App\Enums\LiquorVolume;
use App\Enums\PlacentaPosition;
use App\Models\GyneUltrasound;
use App\Models\QueueToken;
use Illuminate\Validation\Rule;

trait InteractsWithGyneUltrasound
{
    public ?int $fetusCount = null;

    public ?int $gaWeeks = null;

    public ?int $gaDays = null;

    public ?int $fetalHeartRate = null;

    public string $presentation = '';

    public string $placenta = '';

    public string $liquor = '';

    /**
     * Amniotic fluid index in cm, e.g. "12.5".
     */
    public ?string $afi = null;

    public ?int $efwGrams = null;

    public string $impression = '';

    /**
     * Fill the form from this visit's ultrasound report, if one was entered.
     */
    protected function fillGyneUltrasoundForm(QueueToken $token): void
    {
        $this->resetGyneUltrasoundForm();

        $ultrasound = $token->gyneUltrasound;

        if ($ultrasound === null) {
            return;
        }

        $this->fetusCount = $ultrasound->fetus_count;
        $this->gaWeeks = $ultrasound->ga_weeks;
        $this->gaDays = $ultrasound->ga_days;
        $this->fetalHeartRate = $ultrasound->fetal_heart_rate;
        $this->presentation = $ultrasound->presentation->value ?? '';
        $this->placenta = $ultrasound->placenta->value ?? '';
        $this->liquor = $ultrasound->liquor->value ?? '';
        $this->afi = $ultrasound->afi;
        $this->efwGrams = $ultrasound->efw_grams;
        $this->impression = $ultrasound->impression ?? '';
    }

    /**
     * Clear every ultrasound form field.
     */
    protected function resetGyneUltrasoundForm(): void
    {
        $this->reset([
            'fetusCount', 'gaWeeks', 'gaDays', 'fetalHeartRate',
            'presentation', 'placenta', 'liquor', 'afi', 'efwGrams', 'impression',
        ]);
        $this->resetValidation();
    }

    /**
     * Validate the form and save it as this visit's ultrasound report.
     */
    protected function saveGyneUltrasound(QueueToken $token): GyneUltrasound
    {
        $validated = $this->validate([
            'fetusCount' => ['nullable', 'integer', 'min:0', 'max:5'],
            'gaWeeks' => ['nullable', 'integer', 'min:0', 'max:42'],
            'gaDays' => ['nullable', 'integer', 'min:0', 'max:6'],
            'fetalHeartRate' => ['nullable', 'integer', 'min:40', 'max:250'],
            'presentation' => ['nullable', Rule::in(FetalPresentation::values())],
            'placenta' => ['nullable', Rule::in(PlacentaPosition::values())],
            'liquor' => ['nullable', Rule::in(LiquorVolume::values())],
            'afi' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'efwGrams' => ['nullable', 'integer', 'min:10', 'max:6000'],
            'impression' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'patient_id' => $token->patient_id,
            'fetus_count' => $validated['fetusCount'],
            'ga_weeks' => $validated['gaWeeks'],
            'ga_days' => $validated['gaWeeks'] !== null ? ($validated['gaDays'] ?? 0) : null,
            'fetal_heart_rate' => $validated['fetalHeartRate'],
            'presentation' => filled($validated['presentation']) ? $validated['presentation'] : null,
            'placenta' => filled($validated['placenta']) ? $validated['placenta'] : null,
            'liquor' => filled($validated['liquor']) ? $validated['liquor'] : null,
            'afi' => filled($validated['afi']) ? $validated['afi'] : null,
            'efw_grams' => $validated['efwGrams'],
            'impression' => filled($validated['impression']) ? trim($validated['impression']) : null,
        ];

        $ultrasound = GyneUltrasound::query()->firstOrNew(['queue_token_id' => $token->id]);

        if ($ultrasound->exists) {
            $attributes['updated_by'] = auth()->id();
        } else {
            $attributes['scanned_on'] = today()->toDateString();
            $attributes['recorded_by'] = auth()->id();
        }

        $ultrasound->fill($attributes)->save();

        return $ultrasound;
    }

    /**
     * Live EDD preview for the gestational age being entered, e.g. "EDD 12 Jan 2027".
     */
    public function ultrasoundEddPreview(): ?string
    {
        if ($this->gaWeeks === null || $this->gaWeeks < 0 || $this->gaWeeks > 42) {
            return null;
        }

        $ultrasound = new GyneUltrasound([
            'scanned_on' => today(),
            'ga_weeks' => $this->gaWeeks,
            'ga_days' => min(max($this->gaDays ?? 0, 0), 6),
        ]);

        return __('EDD by scan :date', ['date' => $ultrasound->expectedDeliveryDate()->format('d M Y')]);
    }
}
