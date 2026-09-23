{{--
    Printable lab report. Every test is its own A4 sheet (210 × 297 mm) with
    the letterhead at the top and the footer pinned to the bottom.

    @var array{title: string, number: string, mrn: ?string, patient_name: string, age_sex: ?string, phone: ?string, referred_by: ?string, sample_date: ?string, report_date: ?string} $header
    @var list<array> $sections  Sections built by App\Services\LabReportBuilder::buildSection()
    @var string|null $remarks
    @var string|null $banner    Optional notice shown above the report on screen (e.g. "Preview").
--}}
@php
    $lab = config('hospital.lab');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $header['title'] }} — {{ $header['patient_name'] }}</title>
        <style>
            :root {
                --brand: {{ $lab['color'] }};
                --footer: {{ $lab['footer_color'] }};
            }

            * {
                box-sizing: border-box;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            @page {
                size: A4;
                margin: 0;
            }

            body {
                margin: 0;
                padding: 24px;
                color: #111;
                font-family: Helvetica, Arial, sans-serif;
                font-size: 10.5pt;
                line-height: 1.4;
                background: #f4f4f5;
            }

            /* One A4 page per test. */
            .sheet {
                display: flex;
                flex-direction: column;
                width: 210mm;
                min-height: 297mm;
                margin: 0 auto 24px;
                padding: 10mm 10mm 0;
                background: #fff;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
                overflow: hidden;
            }

            .sheet-body {
                flex: 1;
            }

            .sheet-body.empty {
                padding-top: 40mm;
                color: #777;
                text-align: center;
            }

            .banner {
                max-width: 210mm;
                margin: 0 auto 12px;
                padding: 8px 12px;
                border: 1px dashed #b45309;
                background: #fffbeb;
                color: #92400e;
                font-size: 9pt;
                text-align: center;
            }

            /* Letterhead */
            .letterhead {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                gap: 16px;
                padding-bottom: 6px;
                border-bottom: 1.5px solid var(--brand);
            }

            .brand {
                display: flex;
                align-items: center;
                gap: 8px;
                color: var(--brand);
                font-family: Georgia, "Times New Roman", serif;
            }

            .brand-name {
                font-size: 34pt;
                font-weight: 700;
                line-height: 0.95;
                letter-spacing: -0.01em;
            }

            .brand-subtitle {
                font-size: 12pt;
                font-weight: 700;
                line-height: 1.25;
            }

            .registration {
                margin-top: 4px;
                font-size: 9.5pt;
                color: #222;
            }

            .mr {
                text-align: right;
                font-family: Georgia, "Times New Roman", serif;
            }

            .mr-label {
                font-size: 9pt;
                color: #333;
            }

            .mr svg {
                display: block;
                margin: 2px 0 2px auto;
                max-width: 60mm;
                height: 30px;
            }

            .mr-number {
                font-size: 10.5pt;
                letter-spacing: 0.04em;
            }

            /* Patient box */
            .patient {
                display: grid;
                grid-template-columns: 1.2fr 1fr 1.15fr;
                margin: 8px 0 14px;
                border: 1px solid #999;
                border-radius: 4px;
            }

            .patient-col {
                padding: 6px 10px;
            }

            .patient-col + .patient-col {
                border-left: 1px solid #ccc;
            }

            .patient-row {
                display: flex;
                gap: 6px;
                padding: 1px 0;
                font-size: 9.5pt;
            }

            .patient-row .label {
                flex: 0 0 84px;
                color: #444;
                font-weight: 700;
            }

            .patient-row .value {
                flex: 1;
                min-width: 0;
            }

            .patient-col.dates .label {
                flex-basis: 78px;
            }

            .patient-col.dates .value {
                white-space: nowrap;
            }

            .person-name {
                font-weight: 700;
                text-transform: uppercase;
            }

            /* Footer */
            .page-footer {
                margin-top: 16px;
            }

            .disclaimer {
                padding-bottom: 4px;
                border-bottom: 1px solid #111;
                font-family: Georgia, "Times New Roman", serif;
                font-size: 10.5pt;
                text-align: center;
            }

            .signatories {
                display: flex;
                justify-content: space-around;
                gap: 8px;
                padding: 5px 0 7px;
                font-family: Georgia, "Times New Roman", serif;
                text-align: center;
            }

            .signatory-name {
                font-size: 9.5pt;
            }

            .signatory-meta {
                font-size: 7pt;
                line-height: 1.3;
            }

            .address-band {
                margin: 0 -10mm;
                padding: 6px 10mm;
                background: var(--footer);
                color: #fff;
                font-family: Georgia, "Times New Roman", serif;
                font-size: 9.5pt;
                line-height: 1.5;
                text-align: center;
            }

            .address-band .website {
                font-size: 8.5pt;
            }

            /* Test sections */
            .test {
                margin-bottom: 18px;
            }

            .test-title {
                margin: 0 0 6px;
                padding-bottom: 3px;
                border-bottom: 1px solid var(--brand);
                color: var(--brand);
                font-size: 11pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }

            .group-heading {
                margin: 8px 0 4px;
                font-size: 9pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #444;
            }

            table.results {
                width: 100%;
                border-collapse: collapse;
            }

            table.results th {
                text-align: left;
                font-size: 9pt;
                color: #555;
                font-weight: 600;
                border-bottom: 1px solid #ccc;
                padding: 4px 6px;
            }

            table.results td {
                padding: 3px 6px;
                border-bottom: 1px dotted #ddd;
                vertical-align: top;
            }

            .flagged {
                font-weight: 700;
            }

            .flag {
                display: inline-block;
                margin-left: 4px;
                font-size: 8.5pt;
                font-weight: 700;
            }

            .pairs {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0 28px;
            }

            .pair {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                padding: 3px 0;
                border-bottom: 1px dotted #ddd;
            }

            .pair .label {
                color: #333;
            }

            .pair .value {
                font-weight: 600;
                text-align: right;
            }

            .compact-row {
                display: flex;
                align-items: baseline;
                gap: 6px;
                padding: 2px 0;
            }

            .compact-row .leader {
                flex: 1;
                border-bottom: 1px dotted #999;
                transform: translateY(-3px);
            }

            .compact-row .value {
                font-weight: 700;
            }

            .compact-row .range {
                color: #555;
                font-size: 9pt;
            }

            .highlight {
                display: inline-block;
                margin: 6px 0;
                padding: 10px 28px;
                border: 2px solid #111;
                border-radius: 6px;
                font-size: 18pt;
                font-weight: 800;
                letter-spacing: 0.04em;
            }

            table.grid {
                border-collapse: collapse;
            }

            table.grid th,
            table.grid td {
                border: 1px solid #ccc;
                padding: 3px 10px;
                text-align: center;
            }

            table.grid td.label,
            table.grid th.label {
                text-align: left;
            }

            .note {
                margin-top: 6px;
                font-size: 9pt;
                color: #333;
                white-space: pre-line;
            }

            .comment {
                margin-top: 6px;
                padding: 6px 10px;
                border-left: 3px solid var(--brand);
                background: #f7f7f7;
                white-space: pre-line;
            }

            .comment.large {
                font-size: 11pt;
            }

            .remarks {
                margin-top: 10px;
                padding-top: 8px;
                border-top: 1px solid #ccc;
                white-space: pre-line;
            }

            .no-print {
                margin-top: 24px;
                text-align: center;
            }

            .no-print button {
                padding: 8px 16px;
                font-size: 11pt;
                cursor: pointer;
            }

            @media print {
                body {
                    padding: 0;
                    background: #fff;
                }

                /* Slightly under 297mm so rounding never spills onto a blank extra page. */
                .sheet {
                    min-height: 296mm;
                    margin: 0;
                    box-shadow: none;
                }

                .sheet + .sheet {
                    break-before: page;
                }

                .no-print,
                .banner {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        @if (filled($banner ?? null))
            <div class="banner">{{ $banner }}</div>
        @endif

        @forelse ($sections as $section)
            <div class="sheet">
                @include('lab.reports.partials.letterhead')

                <div class="sheet-body">
                    @include('lab.reports.section', ['section' => $section])

                    @if ($loop->last && filled($remarks ?? null))
                        <div class="remarks"><strong>{{ __('Remarks') }}:</strong> {{ $remarks }}</div>
                    @endif
                </div>

                @include('lab.reports.partials.footer')
            </div>
        @empty
            <div class="sheet">
                @include('lab.reports.partials.letterhead')

                <div class="sheet-body empty">{{ __('No results to print yet.') }}</div>

                @include('lab.reports.partials.footer')
            </div>
        @endforelse

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
