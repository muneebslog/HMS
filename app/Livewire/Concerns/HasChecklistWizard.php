<?php

namespace App\Livewire\Concerns;

trait HasChecklistWizard
{
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
     * Jump to a wizard section by key.
     */
    public function setSection(string $section): void
    {
        if (! in_array($section, $this->sectionKeys(), true)) {
            return;
        }

        $this->activeSection = $section;
    }

    /**
     * Advance to the next wizard section.
     */
    public function nextSection(): void
    {
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
