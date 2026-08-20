<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Shift Orders Export') }}</title>
        <style>
            body {
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                margin: 0;
                padding: 24px;
                color: #111;
                font-size: 13px;
            }
            .header {
                margin-bottom: 20px;
            }
            .header h1 {
                margin: 0 0 4px;
                font-size: 1.35rem;
            }
            .meta {
                color: #555;
                margin: 0;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th,
            td {
                border: 1px solid #ccc;
                padding: 8px 10px;
                text-align: left;
                vertical-align: top;
            }
            th {
                background: #f4f4f5;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .empty {
                color: #666;
                padding: 24px 0;
                text-align: center;
            }
            .no-print {
                margin-top: 24px;
                text-align: center;
            }
            .no-print button {
                padding: 8px 16px;
                font-size: 14px;
                cursor: pointer;
            }
            @media print {
                @page {
                    margin: 10mm;
                }
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
        <div class="header">
            <h1>{{ __('Shift Orders Export') }}</h1>
            <p class="meta">
                {{ __('Shift') }}: {{ $shift->opened_at->format('Y-m-d H:i') }}
                · {{ __('Filter') }}: {{ $typeLabel }}
            </p>
        </div>

        @if ($rows->isEmpty())
            <p class="empty">{{ __('No medication or drip orders for this shift and filter.') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('MRN') }}</th>
                        <th>{{ __('Patient') }}</th>
                        <th>{{ __('Phone linked') }}</th>
                        <th>{{ __('Items') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->mrn ?? __('No MRN') }}</td>
                            <td>{{ $row->patient_name }}</td>
                            <td>{{ $row->phone_linked ? __('Yes') : __('No') }}</td>
                            <td>{{ $row->items }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
