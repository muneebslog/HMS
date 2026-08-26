<?php

namespace App\Livewire\Concerns;

use App\Models\HealthAide;

trait HasChecklistWizard
{
    public string $healthAideCode = '';

    public ?int $verifiedHealthAideId = null;

    public string $verifiedHealthAideName = '';

    /**
     * Ordered section keys for the current checklist wizard.
     *
     * @return list<string>
     */
    public function sectionKeys(): array
    {
        return array_values(array_map(
            fn (array $section): string => $section['key'],
            $this->sections()
        ));
    }

    /**
     * Zero-based index of the active wizard section.
     */
    public function currentSectionIndex(): int
    {
        $index = array_search($this->activeSection, $this->sectionKeys(), true);

        return $index === false ? 0 : $index;
    }

    /**
     * Whether the active section is the first wizard step.
     */
    public function isFirstSection(): bool
    {
        return $this->currentSectionIndex() === 0;
    }

    /**
     * Whether the active section is the last wizard step.
     */
    public function isLastSection(): bool
    {
        $keys = $this->sectionKeys();

        return $this->currentSectionIndex() === count($keys) - 1;
    }

    /**
     * Clear verified aide details when the code changes.
     */
    public function updatedHealthAideCode(): void
    {
        $this->verifiedHealthAideId = null;
        $this->verifiedHealthAideName = '';
        $this->resetErrorBag('healthAideCode');
    }

    /**
     * Verify the entered health aide code and capture the aide name.
     */
    public function verifyHealthAideCode(): bool
    {
        $this->validate([
            'healthAideCode' => ['required', 'digits_between:4,6'],
        ], [
            'healthAideCode.required' => __('Enter the health aide code.'),
            'healthAideCode.digits_between' => __('The health aide code must be 4 to 6 digits.'),
        ]);

        $aide = HealthAide::findByPin($this->healthAideCode);

        if ($aide === null) {
            $this->verifiedHealthAideId = null;
            $this->verifiedHealthAideName = '';
            $this->addError('healthAideCode', __('Invalid health aide code.'));

            return false;
        }

        $this->verifiedHealthAideId = $aide->id;
        $this->verifiedHealthAideName = $aide->name;
        $this->resetErrorBag('healthAideCode');

        return true;
    }

    /**
     * Whether a health aide has already been verified for this form.
     */
    public function hasVerifiedHealthAide(): bool
    {
        return $this->verifiedHealthAideId !== null && filled($this->verifiedHealthAideName);
    }

    /**
     * Ensure the header health aide code is verified before leaving that step.
     */
    protected function ensureHeaderCanContinue(): bool
    {
        if ($this->activeSection !== 'header') {
            return true;
        }

        if ($this->hasVerifiedHealthAide()) {
            return true;
        }

        return $this->verifyHealthAideCode();
    }

    /**
     * Jump to a wizard section by key.
     */
    public function setSection(string $section): void
    {
        if (! in_array($section, $this->sectionKeys(), true)) {
            return;
        }

        if ($this->activeSection === 'header' && $section !== 'header' && ! $this->ensureHeaderCanContinue()) {
            return;
        }

        $this->activeSection = $section;
    }

    /**
     * Advance to the next wizard section.
     */
    public function nextSection(): void
    {
        if (! $this->ensureHeaderCanContinue()) {
            return;
        }

        $keys = $this->sectionKeys();
        $index = $this->currentSectionIndex();

        if ($index < count($keys) - 1) {
            $this->activeSection = $keys[$index + 1];
        }
    }

    /**
     * Go back to the previous wizard section.
     */
    public function previousSection(): void
    {
        $keys = $this->sectionKeys();
        $index = $this->currentSectionIndex();

        if ($index > 0) {
            $this->activeSection = $keys[$index - 1];
        }
    }
}
