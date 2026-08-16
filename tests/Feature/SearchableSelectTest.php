<?php

use Illuminate\Support\Facades\Blade;

function renderSearchableSelect(bool $allowCustom = false): string
{
    return Blade::render(
        '<x-searchable-select wire:model="labTestId" :options="$options" placeholder="Search tests" :allow-custom="$allowCustom" />',
        [
            'options' => [
                ['value' => 1, 'label' => 'Complete Blood Count', 'keywords' => 'CBC'],
                ['value' => 2, 'label' => 'Liver Function Test', 'keywords' => 'LFT'],
            ],
            'allowCustom' => $allowCustom,
        ]
    );
}

test('the searchable select field is a text input the user can type in directly', function () {
    $html = renderSearchableSelect();

    expect($html)
        ->toContain('role="combobox"')
        ->toContain('x-ref="input"')
        ->toContain('type="text"')
        ->toContain(':value="displayText"')
        ->toContain('onType($event.target.value)');
});

test('the searchable select no longer hides its search box behind a click', function () {
    $html = renderSearchableSelect();

    expect($html)
        ->not->toContain('x-ref="search"')
        ->not->toContain('x-model="search"');
});

test('the searchable select opens with arrow down, picks with enter and closes with escape', function () {
    $html = renderSearchableSelect();

    expect($html)
        ->toContain('open ? moveHighlight(1) : openList()')
        ->toContain('if (open) { $event.preventDefault(); commit() }')
        ->toContain('if (open) { $event.stopPropagation(); closeList() }');
});

test('the searchable select renders without a wire model bound', function () {
    $html = Blade::render('<x-searchable-select :options="[]" />');

    expect($html)->toContain('role="combobox"');
});

test('the searchable select stays single-select unless multiple is passed', function () {
    expect(renderSearchableSelect())->toContain('multiple: false');
});

test('the searchable select can hold several values and hides the ones already picked', function () {
    $html = Blade::render(
        '<x-searchable-select multiple wire:model="symptomIds" :options="$options" />',
        [
            'options' => [
                ['value' => 1, 'label' => 'Fever', 'keywords' => 'Fever'],
                ['value' => 2, 'label' => 'Cough', 'keywords' => 'Cough'],
            ],
        ]
    );

    expect($html)
        ->toContain('multiple: true')
        ->toContain('this.value = [...this.selectedValues, option.value]')
        ->toContain('! this.selectedValues.some((selected) => String(selected) === String(option.value))')
        ->and($html)->not->toContain('multiple="multiple"');
});

test('the searchable select keeps the dropdown out of the tab order', function () {
    $html = renderSearchableSelect(allowCustom: true);

    expect(substr_count($html, 'tabindex="-1"'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('Complete Blood Count')
        ->and($html)->toContain('Liver Function Test');
});
