<?php

namespace App\Services\PartnerLab;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads the Test Zone (NextStep LIMS) ASP.NET WebForms pages: form fields for
 * postbacks, the Front Desk case list and a case's expanded test list.
 */
class TestZonePageParser
{
    /**
     * Whether the page is the login form (not logged in, or the login failed).
     */
    public function isLoginPage(string $html): bool
    {
        return str_contains($html, 'txtUserPassword');
    }

    /**
     * The values a browser would post back for the page's form: hidden fields, text
     * inputs, ticked checkboxes and selected options. Buttons are left out.
     *
     * @return array<string, string>
     */
    public function formFields(string $html): array
    {
        $xpath = $this->xpath($html);
        $fields = [];

        foreach ($xpath->query('//form//input | //form//select | //form//textarea') as $element) {
            /** @var DOMElement $element */
            $name = $element->getAttribute('name');

            if ($name === '') {
                continue;
            }

            if ($element->tagName === 'select') {
                $options = $xpath->query('.//option', $element);

                if ($options->length === 0) {
                    continue;
                }

                $selected = $xpath->query('.//option[@selected]', $element)->item(0) ?? $options->item(0);
                $fields[$name] = $selected instanceof DOMElement ? $selected->getAttribute('value') : '';

                continue;
            }

            if ($element->tagName === 'textarea') {
                $fields[$name] = $element->textContent;

                continue;
            }

            $type = strtolower($element->getAttribute('type') ?: 'text');

            if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                continue;
            }

            if (in_array($type, ['checkbox', 'radio'], true) && ! $element->hasAttribute('checked')) {
                continue;
            }

            $fields[$name] = $element->getAttribute('value');
        }

        return $fields;
    }

    /**
     * The cases on a Front Desk search result, each with the postback target that expands it.
     *
     * @return list<array{event_target: string, case_no: string, patient_no: ?string, patient_name: ?string, age: ?string, gender: ?string, registered_at: ?CarbonImmutable, reference: ?string}>
     */
    public function cases(string $html): array
    {
        $xpath = $this->xpath($html);
        $grid = $xpath->query('//table[@id="MainContent_GVData"]')->item(0);

        if (! $grid instanceof DOMElement) {
            return [];
        }

        $headers = [];

        foreach ($xpath->query('./tr[1]/th | ./tbody/tr[1]/th', $grid) as $index => $cell) {
            $headers[$this->clean($cell->textContent)] = $index;
        }

        $column = fn (string $name): ?int => $headers[$name] ?? null;
        $cases = [];

        foreach ($xpath->query('./tr | ./tbody/tr', $grid) as $row) {
            /** @var DOMElement $row */
            $showLink = $xpath->query('.//a[contains(@href, "lbshow")]', $row)->item(0);

            if (! $showLink instanceof DOMElement || ! preg_match("/__doPostBack\\('([^']+)'/", $showLink->getAttribute('href'), $matches)) {
                continue;
            }

            $cells = $xpath->query('./td', $row);
            $cell = function (?int $index) use ($cells): ?string {
                if ($index === null || $cells->item($index) === null) {
                    return null;
                }

                $value = $this->clean($cells->item($index)->textContent);

                return $value === '' ? null : $value;
            };

            $caseNo = $cell($column('Lab No'));

            if ($caseNo === null) {
                continue;
            }

            [$age, $gender] = $this->splitAgeGender($cell($column('Gender')));

            $cases[] = [
                'event_target' => $matches[1],
                'case_no' => $caseNo,
                'patient_no' => $cell($column('Patient No')),
                'patient_name' => $cell($column('Patient Name')),
                'age' => $age,
                'gender' => $gender,
                'registered_at' => $this->parseDate($cell($column('Registration Date'))),
                'reference' => $cell($column('Reference')),
            ];
        }

        return $cases;
    }

    /**
     * The tests of the case expanded on the page.
     *
     * @return list<array{partner_test_id: string, code: ?string, name: ?string, status: ?string, report_path: ?string}>
     */
    public function tests(string $html): array
    {
        $xpath = $this->xpath($html);
        $grid = $xpath->query('//table[contains(@id, "GVDetail")]')->item(0);

        if (! $grid instanceof DOMElement) {
            return [];
        }

        $tests = [];

        foreach ($xpath->query('./tr | ./tbody/tr', $grid) as $row) {
            /** @var DOMElement $row */
            $cells = $xpath->query('./td', $row);

            if ($cells->length < 4) {
                continue;
            }

            $rowHtml = $row->ownerDocument->saveHTML($row);

            if (! preg_match('/casedetailid=(\d+)/i', $rowHtml, $idMatch)) {
                continue;
            }

            $printLink = $xpath->query('.//a[contains(@href, "labreport.aspx")]', $row)->item(0);
            $value = fn (int $index): ?string => ($text = $this->clean($cells->item($index)?->textContent ?? '')) === '' ? null : $text;

            $tests[] = [
                'partner_test_id' => $idMatch[1],
                'code' => $value(1),
                'name' => $value(2),
                'status' => $value(3),
                'report_path' => $printLink instanceof DOMElement ? $printLink->getAttribute('href') : null,
            ];
        }

        return $tests;
    }

    /**
     * Split "45 Year(s)/Female" (or "-/Male") into age and gender.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitAgeGender(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        [$age, $gender] = array_pad(explode('/', $value, 2), 2, null);
        $age = trim((string) $age);
        $gender = trim((string) $gender);

        return [in_array($age, ['', '-'], true) ? null : $age, $gender === '' ? null : $gender];
    }

    /**
     * Parse the portal's "29/09/2026 02:14 PM" dates (Pakistan time).
     */
    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('d/m/Y h:i A', $value, config('app.timezone')) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Collapse whitespace (including &nbsp;) and trim.
     */
    private function clean(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '');
    }

    /**
     * Load the page for XPath queries, tolerating the portal's loose HTML.
     */
    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
