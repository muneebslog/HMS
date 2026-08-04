<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Procedure Bill') }} — {{ $procedure->patient->name }}</title>
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

            .bill {
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

            .header-left .facility-meta {
                margin: 8px 0 0;
                padding: 0;
                list-style: none;
                font-size: 8.5pt;
                color: #555;
                line-height: 1.45;
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

            .header-right .bill-no {
                margin: 4px 0 0;
                font-size: 9pt;
                color: #333;
            }

            .header-right .bill-date {
                margin: 2px 0 0;
                font-size: 8.5pt;
                color: #555;
            }

            .header-right .barcode {
                margin-top: 8px;
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

            .section {
                margin-bottom: 20px;
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

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 4px;
            }

            th,
            td {
                border: 1px solid #ccc;
                padding: 8px 10px;
                text-align: left;
            }

            th {
                background: #f5f5f5;
                font-size: 10pt;
            }

            td.amount,
            th.amount {
                text-align: right;
            }

            .totals {
                margin-top: 16px;
                margin-left: auto;
                width: 280px;
            }

            .totals .row {
                padding: 4px 0;
            }

            .totals .balance {
                border-top: 2px solid #111;
                margin-top: 6px;
                padding-top: 8px;
                font-size: 13pt;
                font-weight: 700;
            }

            .footer {
                margin-top: 32px;
                padding-top: 12px;
                border-top: 1px solid #ccc;
                font-size: 9pt;
                color: #666;
                text-align: center;
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
        <div class="bill">
            @php
                $billNumber = 'PROC-'.str_pad((string) $procedure->id, 6, '0', STR_PAD_LEFT);
            @endphp
            <div class="header">
                <div class="header-left">
                    <h1 class="facility-name">{{ config('hospital.name') }}</h1>
                    @if (filled(config('hospital.tagline')))
                        <p class="tagline">{{ config('hospital.tagline') }}</p>
                    @endif
                    @if (filled(config('hospital.address')) || filled(config('hospital.phone')) || filled(config('hospital.email')))
                        <ul class="facility-meta">
                            @if (filled(config('hospital.address')))
                                <li>{{ config('hospital.address') }}</li>
                            @endif
                            @if (filled(config('hospital.phone')))
                                <li>{{ __('Tel') }}: {{ config('hospital.phone') }}</li>
                            @endif
                            @if (filled(config('hospital.email')))
                                <li>{{ config('hospital.email') }}</li>
                            @endif
                        </ul>
                    @endif
                </div>
                <div class="header-right">
                    <p class="doc-title">{{ __('Procedure Bill') }}</p>
                    <p class="bill-no">{{ __('Bill No.') }} {{ $billNumber }}</p>
                    <p class="bill-date">{{ __('Date') }}: {{ now()->format('d M Y, g:i A') }}</p>
                    <div class="barcode">
                        {!! \App\Support\Code39Barcode::svg($billNumber, 36, 1.2) !!}
                        <p class="barcode-label">{{ $billNumber }}</p>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Patient') }}</h2>
                <div class="grid">
                    <div class="row">
                        <span class="label">{{ __('Name') }}</span>
                        <span class="value">{{ $procedure->patient->name }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('MRN') }}</span>
                        <span class="value">{{ $procedure->patient->mrn ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Husband') }}</span>
                        <span class="value">{{ $procedure->patient->husband_name ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Age') }}</span>
                        <span class="value">{{ $procedure->patient->age ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Phone') }}</span>
                        <span class="value">{{ $procedure->patient->contactPhone() ?? '-' }}</span>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Procedure') }}</h2>
                <div class="grid">
                    <div class="row">
                        <span class="label">{{ __('Package') }}</span>
                        <span class="value">{{ $procedure->name }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Doctor') }}</span>
                        <span class="value">{{ $procedure->doctor?->name ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Expected date of delivery') }}</span>
                        <span class="value">{{ $procedure->expected_delivery_date?->format('M j, Y') ?? '-' }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Created') }}</span>
                        <span class="value">{{ $procedure->created_at->format('M j, Y g:i A') }}</span>
                    </div>
                    @if ($procedure->isAdmitted())
                        <div class="row">
                            <span class="label">{{ __('Room') }}</span>
                            <span class="value">{{ $procedure->room_number ?? '-' }}</span>
                        </div>
                        <div class="row">
                            <span class="label">{{ __('Admitted') }}</span>
                            <span class="value">{{ $procedure->admitted_at?->format('M j, Y g:i A') ?? '-' }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">{{ __('Payments') }}</h2>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Mode') }}</th>
                            <th>{{ __('Recorded By') }}</th>
                            <th class="amount">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($procedure->payments as $payment)
                            <tr>
                                <td>{{ $payment->created_at->format('M j, Y g:i A') }}</td>
                                <td>{{ $payment->mode->label() }}</td>
                                <td>{{ $payment->creator?->name ?? '-' }}</td>
                                <td class="amount">{{ number_format($payment->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">{{ __('No payments recorded.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="totals">
                    <div class="row">
                        <span class="label">{{ __('Total package') }}</span>
                        <span class="value">{{ number_format($procedure->full_amount, 2) }}</span>
                    </div>
                    <div class="row">
                        <span class="label">{{ __('Total paid') }}</span>
                        <span class="value">{{ number_format($totalPaid, 2) }}</span>
                    </div>
                    <div class="row balance">
                        <span>{{ __('Balance') }}</span>
                        <span>{{ number_format($balance, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="footer">
                {{ __('Printed on :date', ['date' => now()->format('M j, Y g:i A')]) }}
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
