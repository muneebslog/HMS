<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Invoice :number', ['number' => $invoice->invoice_number]) }}</title>
        <style>
            body {
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                margin: 0;
                padding: 24px;
                color: #111;
            }
            .invoice {
                max-width: 400px;
                margin: 0 auto;
            }
            .header {
                text-align: center;
                border-bottom: 1px dashed #ccc;
                padding-bottom: 16px;
                margin-bottom: 16px;
            }
            .row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
            }
            .label {
                color: #666;
            }
            .total {
                border-top: 1px dashed #ccc;
                margin-top: 16px;
                padding-top: 16px;
                font-size: 1.25rem;
                font-weight: 700;
            }
            .token {
                text-align: center;
                margin: 24px 0;
                padding: 16px;
                border: 2px solid #111;
                border-radius: 8px;
            }
            .token-number {
                font-size: 2.5rem;
                font-weight: 800;
            }
            .no-print {
                margin-top: 24px;
                text-align: center;
            }
            @media print {
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body onload="window.print()">
        <div class="invoice">
            <div class="header">
                <h1>{{ config('app.name') }}</h1>
                <p>{{ __('Invoice') }}</p>
            </div>

            <div class="row">
                <span class="label">{{ __('Invoice #') }}</span>
                <span>{{ $invoice->invoice_number }}</span>
            </div>
            <div class="row">
                <span class="label">{{ __('Date') }}</span>
                <span>{{ $invoice->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="row">
                <span class="label">{{ __('Patient') }}</span>
                <span>{{ $invoice->patient->name }}</span>
            </div>

            <div class="token">
                <div class="label">{{ __('Token Number') }}</div>
                <div class="token-number">{{ $invoice->items->first()?->queueToken?->token_number ?? '-' }}</div>
            </div>

            @foreach ($invoice->items as $item)
                <div class="row">
                    <span>{{ $item->service_name }}</span>
                    <span>{{ number_format($item->price, 2) }}</span>
                </div>
                @if ($item->doctor_name)
                    <div class="row">
                        <span class="label">{{ __('Doctor') }}</span>
                        <span>{{ $item->doctor_name }}</span>
                    </div>
                @endif
            @endforeach

            <div class="row total">
                <span>{{ __('Total') }}</span>
                <span>{{ number_format($invoice->total, 2) }}</span>
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
