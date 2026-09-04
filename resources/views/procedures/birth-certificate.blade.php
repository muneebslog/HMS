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
                padding: 28px 32px;
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 24px;
                border-bottom: 2px solid #111;
                padding-bottom: 14px;
                margin-bottom: 12px;
            }

            .header-left {
                flex: 1;
                min-width: 0;
                text-align: left;
            }

            .header-left .facility-name {
                margin: 0;
                font-size: 17pt;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                line-height: 1.2;
            }

            .header-left .tagline {
                margin: 4px 0 0;
                font-size: 9.5pt;
                color: #444;
                font-style: italic;
            }

            .header-right {
                text-align: right;
                flex-shrink: 0;
            }

            .header-right .issued {
                margin: 0 0 6px;
                font-size: 8.5pt;
                color: #555;
            }

            .header-right .barcode {
                display: inline-block;
            }

            .header-right .barcode svg {
                display: block;
                margin-left: auto;
            }

            .header-right .barcode-label {
                margin: 2px 0 0;
                font-size: 8pt;
                font-family: Consolas, "Courier New", monospace;
                letter-spacing: 0.08em;
            }

            .doc-title {
                margin: 0 0 18px;
                text-align: center;
                font-size: 15pt;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                text-decoration: underline;
            }

            .section {
                margin-bottom: 16px;
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
                gap: 8px 24px;
            }

            .grid-full {
                grid-column: 1 / -1;
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

            .witness {
                margin-top: 28px;
                font-size: 10.5pt;
                line-height: 1.55;
            }

            .witness p {
                margin: 0 0 10px;
            }

            .sign-row {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 16px;
                margin-top: 36px;
            }

            .signature-line {
                border-top: 1px solid #333;
                margin-top: 36px;
                padding-top: 6px;
                text-align: center;
                font-size: 9pt;
                color: #555;
            }

            .contact {
                margin-top: 28px;
                border-top: 1px solid #ccc;
                padding-top: 12px;
                font-size: 9.5pt;
                color: #333;
                line-height: 1.5;
                text-align: center;
            }

            .contact p {
                margin: 0;
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
        @php
            $docNumber = 'BC-'.str_pad((string) $procedure->id, 6, '0', STR_PAD_LEFT);
            $birthAt = $certificate?->born_at
                ?? $deliveryNote?->delivered_at
                ?? $dischargeDetail?->procedure_time;
            $sex = $certificate?->sex
                ?? $dischargeDetail?->baby_sex
                ?? $deliveryNote?->baby_sex;
            $sexLabel = match (strtolower((string) $sex)) {
                'male', 'm' => __('Male'),
                'female', 'f' => __('Female'),
                default => filled($sex) ? ucfirst((string) $sex) : '-',
            };
            $motherName = $certificate?->mother_name ?? $procedure->patient->name;
            $fatherName = $certificate?->father_name ?? $procedure->patient->husband_name;
            $motherAge = $certificate?->mother_age ?? $procedure->patient->age;
            $motherCnic = $certificate?->mother_cnic ?? $procedure->patient->cnic;
        @endphp

        <div class="sheet">
            <div class="header">
                <div class="header-left">
                    <h1 class="facility-name">{{ config('hospital.name') }}</h1>
                    @if (filled(config('hospital.tagline')))
                        <p class="tagline">{{ config('hospital.tagline') }}</p>
                    @endif
                </div>
                <div class="header-right">
                    <p class="issued">{{ __('Issued') }} {{ now()->format('d M Y') }}</p>
                    <div class="barcode">
                        {!! \App\Support\Code39Barcode::svg($docNumber, 36, 1.2) !!}
                        <p class="barcode-label">{{ $docNumber }}</p>
                    </div>
                </div>
            </div>

            <p class="doc-title">{{ __('Birth Certificate') }}</p>

            <div class="section">
                <h2 class="section-title">{{ __('Child Details') }}</h2>
                <div class="grid">
                    <div class="row">
                        <span class="label">{{ __('Name (if given)') }}</span>
                        <span class="value">{{ $certificate?->baby_name ?: '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Sex') }}</span>
                        <span class="value">{{ $sexLabel }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Day and Date of Birth') }}</span>
                        <span class="value">{{ $birthAt?->format('l, d M Y, g:i A') ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Status') }}</span>
                        <span class="value">{{ $certificate?->status?->label() ?? __('Living') }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('This Birth') }}</span>
                        <span class="value">{{ $certificate?->multiplicity?->label() ?? __('Single') }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('This Child Born') }}</span>
                        <span class="value">{{ $certificate?->childOrderLabel() ?? '-' }}</span>
                    </div>
                    @unless ($certificate)
                        <div class="row">
                            <span class="label">{{ __('Weight') }}</span>
                            <span class="value">{{ $dischargeDetail?->baby_weight ?? $deliveryNote?->baby_weight ?? '-' }}</span>
                        </div>
                        <div class="row">
                            <span class="label">{{ __('Mode of Delivery') }}</span>
                            <span class="value">{{ $deliveryNote?->labour_type ?? $procedure->procedureType?->name ?? '-' }}</span>
                        </div>
                    @endunless
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Parents & Family') }}</h2>
                <div class="grid">
                    <div class="row">
                        <span class="label">{{ __('Father Name') }}</span>
                        <span class="value">{{ $fatherName ?: '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Mother Name') }}</span>
                        <span class="value">{{ $motherName ?: '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Grand Father Name') }}</span>
                        <span class="value">{{ $certificate?->grandfather_name ?: '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Mother\'s Father Name') }}</span>
                        <span class="value">{{ $certificate?->maternal_grandfather_name ?: '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Father Age') }}</span>
                        <span class="value">{{ $certificate?->father_age ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Mother Age') }}</span>
                        <span class="value">{{ $motherAge ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Father CNIC') }}</span>
                        <span class="value">{{ $certificate?->father_cnic ?: '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Mother CNIC') }}</span>
                        <span class="value">{{ $motherCnic ?: '-' }}</span>
                    </div>
                    <div class="row grid-full">
                        <span class="label">{{ __('Home Address') }}</span>
                        <span class="value">{{ $certificate?->home_address ?: '-' }}</span>
                    </div>
                </div>
            </div>

            <div class="witness">
                <p>
                    {{ __('IN WITNESS WHEREOF; the said hospital has caused this certificate to sign by its duty authorized officers hereto affixed.') }}
                </p>
            </div>

            <div class="sign-row">
                <div class="signature-line">{{ __('Staff Nurse / Midwife') }}</div>
                <div class="signature-line">{{ __('Doctor') }}</div>
                <div class="signature-line">{{ __('Administrator') }}</div>
            </div>

            <div class="contact">
                <p><strong>{{ __('Address') }}:</strong> {{ config('hospital.address') }}</p>
                <p><strong>{{ __('Tel. No.') }}:</strong> {{ config('hospital.phone') }}</p>
                <p><strong>{{ __('Email address') }}:</strong> {{ config('hospital.email') }}</p>
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
