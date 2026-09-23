{{--
    Printable lab report. Each test prints on its own page, and the letterhead
    and footer repeat on every printed page.

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
                margin: 8mm 8mm 6mm;
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

            .sheet {
                max-width: 210mm;
                margin: 0 auto;
                padding: 10mm 10mm 0;
                background: #fff;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
            }

            table.page {
                width: 100%;
                border-collapse: collapse;
            }

            table.page > thead > tr > td,
            table.page > tbody > tr > td,
            table.page > tfoot > tr > td {
                padding: 0;
            }

            .footer-space {
                display: none;
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
                margin-top: 28px;
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
                break-inside: avoid;
            }

            .test.new-page {
                break-before: page;
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

                .sheet {
                    max-width: none;
                    padding: 0;
                    box-shadow: none;
                }

                .no-print,
                .banner {
                    display: none;
                }

                /* Reserve room on every page for the fixed footer. */
                .footer-space {
                    display: block;
                    height: 34mm;
                }

                .page-footer {
                    position: fixed;
                    right: 0;
                    bottom: 0;
                    left: 0;
                    margin: 0;
                }

                .address-band {
                    margin: 0;
                    padding: 6px 0;
                }
            }
        </style>
    </head>
    <body>
        @if (filled($banner ?? null))
            <div class="banner">{{ $banner }}</div>
        @endif

        <div class="sheet">
            <table class="page">
                <thead>
                    <tr>
                        <td>
                            <div class="letterhead">
                                <div>
                                    <div class="brand">
                                        <span class="brand-name">{{ $lab['brand'] }}</span>
                                        <span class="brand-subtitle">{!! nl2br(e(str_replace(' ', "\n", $lab['brand_subtitle']))) !!}</span>
                                    </div>
                                    @if (filled($lab['registration']))
                                        <div class="registration">{{ $lab['registration'] }}</div>
                                    @endif
                                </div>

                                @if (filled($header['mrn']))
                                    <div class="mr">
                                        <div class="mr-label">{{ __('Medical Record No') }}</div>
                                        {!! \App\Support\Code39Barcode::svg($header['mrn'], 30, 1) !!}
                                        <div class="mr-number">{{ $header['mrn'] }}</div>
                                    </div>
                                @endif
                            </div>

                            <div class="patient">
                                <div class="patient-col">
                                    <div class="patient-row">
                                        <span class="label">{{ __('Patient') }}</span>
                                        <span class="value person-name">{{ $header['patient_name'] }}</span>
                                    </div>
                                    <div class="patient-row">
                                        <span class="label">{{ __('Age / Sex') }}</span>
                                        <span class="value">{{ $header['age_sex'] ?? '—' }}</span>
                                    </div>
                                    <div class="patient-row">
                                        <span class="label">{{ __('Phone') }}</span>
                                        <span class="value">{{ $header['phone'] ?? '—' }}</span>
                                    </div>
                                </div>
                                <div class="patient-col">
                                    <div class="patient-row">
                                        <span class="label">{{ __('Receipt No') }}</span>
                                        <span class="value">{{ $header['number'] }}</span>
                                    </div>
                                    <div class="patient-row">
                                        <span class="label">{{ __('MR No') }}</span>
                                        <span class="value">{{ $header['mrn'] ?? '—' }}</span>
                                    </div>
                                    <div class="patient-row">
                                        <span class="label">{{ __('Referred By') }}</span>
                                        <span class="value">{{ $header['referred_by'] ?? __('Self') }}</span>
                                    </div>
                                </div>
                                <div class="patient-col dates">
                                    <div class="patient-row">
                                        <span class="label">{{ __('Sample Date') }}</span>
                                        <span class="value">{{ $header['sample_date'] ?? '—' }}</span>
                                    </div>
                                    <div class="patient-row">
                                        <span class="label">{{ __('Report Date') }}</span>
                                        <span class="value">{{ $header['report_date'] ?? '—' }}</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </thead>

                <tfoot>
                    <tr>
                        <td><div class="footer-space"></div></td>
                    </tr>
                </tfoot>

                <tbody>
                    <tr>
                        <td>
                            @foreach ($sections as $section)
                                @include('lab.reports.section', ['section' => $section, 'startsNewPage' => ! $loop->first])
                            @endforeach

                            @if (filled($remarks ?? null))
                                <div class="remarks"><strong>{{ __('Remarks') }}:</strong> {{ $remarks }}</div>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="page-footer">
                @if (filled($lab['disclaimer']))
                    <div class="disclaimer">{{ $lab['disclaimer'] }}</div>
                @endif

                @if (! empty($lab['signatories']))
                    <div class="signatories">
                        @foreach ($lab['signatories'] as $signatory)
                            <div>
                                <div class="signatory-name">{{ $signatory['name'] }}</div>
                                @if (filled($signatory['qualification'] ?? null))
                                    <div class="signatory-meta">{{ $signatory['qualification'] }}</div>
                                @endif
                                @if (filled($signatory['title'] ?? null))
                                    <div class="signatory-meta">{{ $signatory['title'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="address-band">
                    <div>{{ collect([$lab['address'], $lab['phone']])->filter()->implode(', ') }}</div>
                    @if (filled($lab['website']))
                        <div class="website">{{ __('Website') }}: {{ $lab['website'] }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
