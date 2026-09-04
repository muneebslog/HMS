<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Payment Receipt') }} — {{ $procedure->patient->name }}</title>
        <style>
            * {
                box-sizing: border-box;
            }

            @page {
                size: A4;
                margin: 14mm;
            }

            body {
                margin: 0;
                padding: 24px;
                color: #111;
                font-family: Helvetica, Arial, sans-serif;
                font-size: 11pt;
                line-height: 1.4;
            }

            .receipt {
                max-width: 210mm;
                margin: 0 auto;
            }

            .top-meta {
                display: flex;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 10px;
                font-size: 10pt;
            }

            .doc-title {
                margin: 0 0 18px;
                text-align: center;
                font-size: 16pt;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                text-decoration: underline;
            }

            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 28px;
                margin-bottom: 20px;
            }

            .info-row {
                display: flex;
                gap: 8px;
                align-items: baseline;
            }

            .info-row .label {
                color: #333;
                white-space: nowrap;
            }

            .info-row .value {
                font-weight: 600;
                border-bottom: 1px dotted #999;
                flex: 1;
                min-width: 0;
                padding-bottom: 1px;
            }

            table.charges {
                width: 100%;
                border-collapse: collapse;
                margin-top: 4px;
            }

            table.charges th,
            table.charges td {
                border: 1px solid #333;
                padding: 8px 10px;
            }

            table.charges th {
                background: #f5f5f5;
                font-size: 10pt;
                text-align: left;
            }

            table.charges th.amount,
            table.charges td.amount {
                text-align: right;
                white-space: nowrap;
            }

            table.charges th.sr,
            table.charges td.sr {
                width: 56px;
                text-align: center;
            }

            table.charges tfoot td {
                font-weight: 700;
            }

            .witness {
                margin-top: 28px;
                font-size: 10pt;
                color: #333;
            }

            .signatures {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 24px;
                margin-top: 48px;
                text-align: center;
                font-size: 10pt;
            }

            .signatures .line {
                border-top: 1px solid #333;
                padding-top: 6px;
            }

            .footer {
                margin-top: 36px;
                padding-top: 12px;
                border-top: 1px solid #ccc;
                font-size: 9pt;
                color: #444;
                line-height: 1.5;
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
        <div class="receipt">
            @php
                $regNumber = $procedure->patient->mrn ?? ('R-'.str_pad((string) $procedure->id, 5, '0', STR_PAD_LEFT));
            @endphp

            <div class="top-meta">
                <div>{{ __('PHC REG #') }} {{ $regNumber }}</div>
                <div>{{ __('Date') }}: {{ now()->format('d-m-Y') }}</div>
            </div>

            <h1 class="doc-title">{{ __('Payment Receipt') }}</h1>

            <div class="info-grid">
                <div class="info-row">
                    <span class="label">{{ __('PATIENT NAME') }} :</span>
                    <span class="value">{{ $procedure->patient->name }}</span>
                </div>
                <div class="info-row">
                    <span class="label">{{ __('W/O') }} :</span>
                    <span class="value">{{ $procedure->patient->husband_name ?? '-' }}</span>
                </div>
                <div class="info-row">
                    <span class="label">{{ __('Consultant') }} :</span>
                    <span class="value">{{ $procedure->doctor?->name ?? '-' }}</span>
                </div>
                <div class="info-row">
                    <span class="label">{{ __('Procedure') }} :</span>
                    <span class="value">{{ $procedure->name }}</span>
                </div>
                <div class="info-row">
                    <span class="label">{{ __('Date of Admission') }} :</span>
                    <span class="value">{{ $procedure->admitted_at?->format('d-m-Y') ?? '-' }}</span>
                </div>
                <div class="info-row">
                    <span class="label">{{ __('Date of Discharge') }} :</span>
                    <span class="value">{{ $procedure->discharged_at?->format('d-m-Y') ?? '-' }}</span>
                </div>
            </div>

            <table class="charges">
                <thead>
                    <tr>
                        <th class="sr">{{ __('Sr. No.') }}</th>
                        <th>{{ __('Operation / Type of Charges') }}</th>
                        <th class="amount">{{ __('Amount (Rs.)') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $index => $item)
                        <tr>
                            <td class="sr">{{ $index + 1 }}</td>
                            <td>{{ $item->name }}</td>
                            <td class="amount">{{ number_format($item->amount, 0) }}/-</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">{{ __('Grand Total:') }}</td>
                        <td class="amount">{{ __('Rs.') }} {{ number_format($invoice->total, 0) }}/-</td>
                    </tr>
                </tfoot>
            </table>

            <p class="witness">
                {{ __('IN WITNESS WHEREOF, the said hospital has caused this payment receipt to be signed by its duty authorized officers hereto affixed.') }}
            </p>

            <div class="signatures">
                <div class="line">{{ __('Staff Nurse / Midwife') }}</div>
                <div class="line">{{ __('Doctor') }}</div>
                <div class="line">{{ __('Administrator') }}</div>
            </div>

            <div class="footer">
                @if (filled(config('hospital.address')))
                    <div>{{ __('Address') }}: {{ config('hospital.address') }}</div>
                @endif
                @if (filled(config('hospital.phone')))
                    <div>{{ __('Tel. No.') }} : {{ config('hospital.phone') }}</div>
                @endif
                @if (filled(config('hospital.email')))
                    <div>{{ __('Email address') }}: {{ config('hospital.email') }}</div>
                @endif
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
