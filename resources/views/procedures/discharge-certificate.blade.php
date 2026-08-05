<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Discharge Certificate') }} — {{ $procedure->patient->name }}</title>
        <style>
            * {
                box-sizing: border-box;
            }

            @page {
                size: A4;
                margin: 12mm;
            }

            body {
                margin: 0;
                padding: 24px;
                color: #111;
                font-family: Helvetica, Arial, sans-serif;
                font-size: 11pt;
                line-height: 1.4;
            }

            .sheet {
                max-width: 210mm;
                margin: 0 auto;
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 24px;
                border-bottom: 2px solid #111;
                padding-bottom: 14px;
                margin-bottom: 20px;
            }

            .header-left {
                flex: 1;
                min-width: 0;
            }

            .header-left .facility-name {
                margin: 0;
                font-size: 16pt;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                line-height: 1.2;
            }

            .header-left .tagline {
                margin: 4px 0 0;
                font-size: 9pt;
                color: #444;
                font-style: italic;
            }

            .header-right {
                text-align: right;
                flex-shrink: 0;
            }

            .header-right .doc-title {
                margin: 0;
                font-size: 12pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.06em;
            }

            .header-right .doc-no {
                margin: 4px 0 0;
                font-size: 9pt;
                color: #333;
            }

            .header-right .doc-date {
                margin: 2px 0 0;
                font-size: 8.5pt;
                color: #555;
            }

            .section {
                margin-bottom: 18px;
            }

            .section-title {
                margin: 0 0 8px;
                font-size: 11pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                border-bottom: 1px solid #ccc;
                padding-bottom: 4px;
            }

            .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px 24px;
            }

            .grid-3 {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 6px 24px;
            }

            .row {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                padding: 2px 0;
            }

            .label {
                color: #555;
            }

            .value {
                font-weight: 600;
                text-align: right;
            }

            .block {
                border: 1px solid #ccc;
                border-radius: 4px;
                padding: 10px 12px;
                min-height: 60px;
                white-space: pre-wrap;
                font-size: 10.5pt;
            }

            .signatures {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                margin-top: 40px;
            }

            .signature-line {
                border-top: 1px solid #333;
                margin-top: 40px;
                padding-top: 6px;
                text-align: center;
                font-size: 9.5pt;
                color: #555;
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
                }

                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body onload="window.print()">
        <div class="sheet">
            @php
                $docNumber = 'DC-'.str_pad((string) $procedure->id, 6, '0', STR_PAD_LEFT);
            @endphp
            <div class="header">
                <div class="header-left">
                    <h1 class="facility-name">{{ config('hospital.name') }}</h1>
                    @if (filled(config('hospital.tagline')))
                        <p class="tagline">{{ config('hospital.tagline') }}</p>
                    @endif
                </div>
                <div class="header-right">
                    <p class="doc-title">{{ __('Discharge Certificate') }}</p>
                    <p class="doc-no">{{ __('No.') }} {{ $docNumber }}</p>
                    <p class="doc-date">{{ __('Date') }}: {{ now()->format('d M Y, g:i A') }}</p>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Patient') }}</h2>
                <div class="grid-3">
                    <div class="row">
                        <span class="label">{{ __('Name') }}</span>
                        <span class="value">{{ $procedure->patient->name }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('MRN') }}</span>
                        <span class="value">{{ $procedure->patient->mrn ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Age') }}</span>
                        <span class="value">{{ $procedure->patient->age ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Husband') }}</span>
                        <span class="value">{{ $procedure->patient->husband_name ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Room') }}</span>
                        <span class="value">{{ $procedure->room?->number ?? $procedure->room_number ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Blood Group') }}</span>
                        <span class="value">{{ $dischargeDetail?->blood_group ?? '-' }}</span>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Admission & Procedure') }}</h2>
                <div class="grid-3">
                    <div class="row">
                        <span class="label">{{ __('Admitted') }}</span>
                        <span class="value">{{ $procedure->admitted_at?->format('d M Y, g:i A') ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Discharged') }}</span>
                        <span class="value">{{ $procedure->discharged_at?->format('d M Y, g:i A') ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Procedure') }}</span>
                        <span class="value">{{ $procedure->procedureType?->name ?? $procedure->name }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Surgeon / Doctor') }}</span>
                        <span class="value">{{ $procedure->doctor?->name ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Procedure Time') }}</span>
                        <span class="value">{{ $dischargeDetail?->procedure_time?->format('d M Y, g:i A') ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Parity') }}</span>
                        <span class="value">{{ $dischargeDetail?->parity ?? '-' }}</span>
                    </div>
                </div>
                <div class="row" style="margin-top: 8px;">
                    <span class="label">{{ __('Indication') }}</span>
                    <span class="value">{{ $dischargeDetail?->indication ?? '-' }}</span>
                </div>
            </div>

            @if ($procedure->procedureType?->requires_birth_certificate)
                <div class="section">
                    <h2 class="section-title">{{ __('Baby Details') }}</h2>
                    <div class="grid-3">
                        <div class="row">
                            <span class="label">{{ __('Sex') }}</span>
                            <span class="value">{{ $dischargeDetail?->baby_sex ?? '-' }}</span>
                        </div>
                        <div class="row">
                            <span class="label">{{ __('Weight') }}</span>
                            <span class="value">{{ $dischargeDetail?->baby_weight ?? '-' }}</span>
                        </div>
                        <div class="row">
                            <span class="label">{{ __('Condition') }}</span>
                            <span class="value">{{ $dischargeDetail?->baby_condition ?? '-' }}</span>
                        </div>
                    </div>
                </div>
            @endif

            <div class="section">
                <h2 class="section-title">{{ __('Rx / Advice on Discharge') }}</h2>
                <div class="block">{{ $dischargeDetail?->rx_text ?? __('Not recorded.') }}</div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Outcome Summary') }}</h2>
                <div class="block">{{ $dischargeDetail?->outcome_summary ?? __('Not recorded.') }}</div>
            </div>

            <div class="section">
                <div class="row">
                    <span class="label">{{ __('Stitch Removal Date') }}</span>
                    <span class="value">{{ $dischargeDetail?->stitch_removal_date?->format('d M Y') ?? '-' }}</span>
                </div>
            </div>

            <div class="signatures">
                <div class="signature-line">{{ __('Attending Doctor') }}</div>
                <div class="signature-line">{{ __('Ward Incharge') }}</div>
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
