<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Birth Certificate') }} — {{ $procedure->patient->name }}</title>
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
                border: 3px double #111;
                padding: 32px;
            }

            .header {
                text-align: center;
                border-bottom: 2px solid #111;
                padding-bottom: 16px;
                margin-bottom: 24px;
            }

            .header .facility-name {
                margin: 0;
                font-size: 17pt;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }

            .header .tagline {
                margin: 4px 0 0;
                font-size: 9.5pt;
                color: #444;
                font-style: italic;
            }

            .header .doc-title {
                margin: 14px 0 0;
                font-size: 15pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                text-decoration: underline;
            }

            .doc-no {
                text-align: right;
                font-size: 9pt;
                color: #555;
                margin-bottom: 16px;
            }

            .section {
                margin-bottom: 20px;
            }

            .section-title {
                margin: 0 0 10px;
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
                gap: 10px 24px;
            }

            .row {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                padding: 3px 0;
                border-bottom: 1px dotted #ccc;
            }

            .label {
                color: #555;
            }

            .value {
                font-weight: 600;
                text-align: right;
            }

            .signatures {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                margin-top: 50px;
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
                $docNumber = 'BC-'.str_pad((string) $procedure->id, 6, '0', STR_PAD_LEFT);
                $birthAt = $deliveryNote?->delivered_at ?? $dischargeDetail?->procedure_time;
            @endphp

            <div class="doc-no">{{ __('No.') }} {{ $docNumber }} &middot; {{ __('Issued') }} {{ now()->format('d M Y') }}</div>

            <div class="header">
                <h1 class="facility-name">{{ config('hospital.name') }}</h1>
                @if (filled(config('hospital.tagline')))
                    <p class="tagline">{{ config('hospital.tagline') }}</p>
                @endif
                <p class="doc-title">{{ __('Birth Certificate') }}</p>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Baby Details') }}</h2>
                <div class="grid">
                    <div class="row">
                        <span class="label">{{ __('Sex') }}</span>
                        <span class="value">{{ $dischargeDetail?->baby_sex ?? $deliveryNote?->baby_sex ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Weight') }}</span>
                        <span class="value">{{ $dischargeDetail?->baby_weight ?? $deliveryNote?->baby_weight ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Date & Time of Birth') }}</span>
                        <span class="value">{{ $birthAt?->format('d M Y, g:i A') ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Condition') }}</span>
                        <span class="value">{{ $dischargeDetail?->baby_condition ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('APGAR Score') }}</span>
                        <span class="value">{{ $deliveryNote?->apgar_score ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Mode of Delivery') }}</span>
                        <span class="value">{{ $deliveryNote?->labour_type ?? $procedure->procedureType?->name ?? '-' }}</span>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Mother Details') }}</h2>
                <div class="grid">
                    <div class="row">
                        <span class="label">{{ __('Mother\'s Name') }}</span>
                        <span class="value">{{ $procedure->patient->name }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('MRN') }}</span>
                        <span class="value">{{ $procedure->patient->mrn ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Husband\'s Name') }}</span>
                        <span class="value">{{ $procedure->patient->husband_name ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('CNIC') }}</span>
                        <span class="value">{{ $procedure->patient->cnic ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Age') }}</span>
                        <span class="value">{{ $procedure->patient->age ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Parity') }}</span>
                        <span class="value">{{ $dischargeDetail?->parity ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Attending Doctor') }}</span>
                        <span class="value">{{ $deliveryNote?->obstetrician ?? $procedure->doctor?->name ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Place of Birth') }}</span>
                        <span class="value">{{ config('hospital.name') }}</span>
                    </div>
                </div>
            </div>

            <div class="signatures">
                <div class="signature-line">{{ __('Attending Doctor / Obstetrician') }}</div>
                <div class="signature-line">{{ __('Medical Superintendent') }}</div>
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
