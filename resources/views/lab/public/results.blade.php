{{--
    Public lab results page (QR on the lab slip). No login; found only by the invoice's random code.

    @var \App\Models\LabInvoice $labInvoice
    @var \Illuminate\Support\Collection<int, array{id: int, name: string, status: array{label: string, tone: string}, has_report: bool}> $items
    @var int $readyCount
--}}
@php
    $lab = config('hospital.lab');
    $patient = $labInvoice->patient;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('Lab Results') }} — {{ $lab['brand'] }} {{ $lab['brand_subtitle'] }}</title>
        <style>
            :root {
                --brand: {{ $lab['color'] }};
                --footer: {{ $lab['footer_color'] }};
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: #f4f4f5;
                color: #18181b;
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                line-height: 1.45;
            }

            .page {
                max-width: 560px;
                margin: 0 auto;
                padding: 16px;
            }

            .brand {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 4px 16px;
                color: var(--brand);
                font-family: Georgia, "Times New Roman", serif;
            }

            .brand-name {
                font-size: 30px;
                font-weight: 700;
                line-height: 1;
            }

            .brand-subtitle {
                font-size: 14px;
                font-weight: 700;
                line-height: 1.2;
            }

            .card {
                background: #fff;
                border: 1px solid #e4e4e7;
                border-radius: 14px;
                padding: 16px;
                margin-bottom: 14px;
            }

            .greeting {
                margin: 0 0 4px;
                font-size: 20px;
                font-weight: 700;
                text-transform: uppercase;
            }

            .meta {
                margin: 0;
                color: #52525b;
                font-size: 14px;
            }

            .summary {
                margin-top: 12px;
                padding: 10px 12px;
                border-radius: 10px;
                background: #f0f9ff;
                color: #075985;
                font-size: 14px;
                font-weight: 600;
            }

            .summary.done {
                background: #f0fdf4;
                color: #166534;
            }

            h2 {
                margin: 0 0 10px;
                font-size: 15px;
                color: #3f3f46;
            }

            .test {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 12px 0;
                border-top: 1px solid #f4f4f5;
            }

            .test:first-of-type {
                border-top: 0;
            }

            .test-name {
                font-weight: 600;
            }

            .pill {
                display: inline-block;
                margin-top: 4px;
                padding: 2px 8px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 600;
            }

            .pill.ready {
                background: #dcfce7;
                color: #166534;
            }

            .pill.waiting {
                background: #fef3c7;
                color: #92400e;
            }

            .btn {
                flex-shrink: 0;
                display: inline-block;
                padding: 9px 14px;
                border-radius: 10px;
                background: var(--brand);
                color: #fff;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                white-space: nowrap;
            }

            .btn.block {
                display: block;
                margin-top: 12px;
                text-align: center;
            }

            .note {
                color: #71717a;
                font-size: 13px;
            }

            .footer {
                margin-top: 20px;
                padding: 14px;
                border-radius: 14px;
                background: var(--footer);
                color: #fff;
                font-size: 13px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="brand">
                <span class="brand-name">{{ $lab['brand'] }}</span>
                <span class="brand-subtitle">{!! nl2br(e(str_replace(' ', "\n", $lab['brand_subtitle']))) !!}</span>
            </div>

            <div class="card">
                <p class="greeting">{{ $patient?->name }}</p>
                <p class="meta">
                    {{ __('Receipt :number', ['number' => $labInvoice->invoice_number]) }}
                    · {{ $labInvoice->created_at->format('d M Y, g:i A') }}
                </p>

                @if ($items->isNotEmpty() && $readyCount === $items->count())
                    <div class="summary done">{{ __('All your results are ready.') }}</div>
                @elseif ($readyCount > 0)
                    <div class="summary">{{ __(':ready of :total results are ready. Please check again later for the rest.', ['ready' => $readyCount, 'total' => $items->count()]) }}</div>
                @else
                    <div class="summary">{{ __('Your tests are in process. Please check again later.') }}</div>
                @endif

                @if ($readyCount > 1)
                    <a class="btn block" href="{{ route('lab.public.report', $labInvoice->public_token) }}" target="_blank" rel="noopener">
                        {{ __('View all ready reports') }}
                    </a>
                @endif
            </div>

            <div class="card">
                <h2>{{ __('Your tests') }}</h2>

                @foreach ($items as $item)
                    <div class="test">
                        <div>
                            <div class="test-name">{{ $item['name'] }}</div>
                            <span class="pill {{ $item['status']['tone'] }}">{{ $item['status']['label'] }}</span>
                        </div>

                        @if ($item['has_report'])
                            <a class="btn" href="{{ route('lab.public.report', ['token' => $labInvoice->public_token, 'item' => $item['id']]) }}" target="_blank" rel="noopener">
                                {{ __('View report') }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="note">{{ __('Keep this link private — anyone with it can see these results.') }}</p>

            <div class="footer">
                <div>{{ collect([$lab['address'], $lab['phone']])->filter()->implode(', ') }}</div>
                @if (filled($lab['website']))
                    <div>{{ $lab['website'] }}</div>
                @endif
            </div>
        </div>
    </body>
</html>
