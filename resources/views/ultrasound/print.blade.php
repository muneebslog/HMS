<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Ultrasound Report') }}</title>
        <style>
            * {
                box-sizing: border-box;
            }

            @page {
                size: A4;
                margin: 0;
            }

            body {
                margin: 0;
                padding: 0;
                width: 210mm;
                height: 297mm;
                font-family: Helvetica, Arial, sans-serif;
                font-size: 10pt;
                line-height: 1;
                position: relative;
                color: #000;
            }

            .field {
                position: absolute;
                white-space: nowrap;
            }

            .no-print {
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 1000;
            }

            @media print {
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body onload="window.print()">
        {{-- Header --}}
        <div class="field" style="left: 35mm; top: 60mm;">{{ $report->name }}</div>
        <div class="field" style="left: 155mm; top: 60mm;">{{ $report->age ?? '' }}</div>
        <div class="field" style="left: 180mm; top: 60mm;">{{ $report->report_date->format('d-m-Y') }}</div>
        <div class="field" style="left: 80mm; top: 70mm;">{{ $report->fetus_status }}</div>

        {{-- Measurements --}}
        <div class="field" style="left: 110mm; top: 90mm;">{{ $report->bpd_meas }}</div>
        <div class="field" style="left: 180mm; top: 90mm;">{{ $report->bpd_age }}</div>

        <div class="field" style="left: 110mm; top: 100mm;">{{ $report->femur_meas }}</div>
        <div class="field" style="left: 180mm; top: 100mm;">{{ $report->femur_age }}</div>

        <div class="field" style="left: 110mm; top: 110mm;">{{ $report->ac_meas }}</div>
        <div class="field" style="left: 180mm; top: 110mm;">{{ $report->ac_age }}</div>

        <div class="field" style="left: 110mm; top: 120mm;">{{ $report->crl_meas }}</div>
        <div class="field" style="left: 180mm; top: 120mm;">{{ $report->crl_age }}</div>

        {{-- Clinical details --}}
        <div class="field" style="left: 50mm; top: 135mm;">{{ $report->gest_age }}</div>
        <div class="field" style="left: 175mm; top: 135mm;">{{ $report->edd }}</div>
        <div class="field" style="left: 55mm; top: 145mm;">{{ $report->heart_motion }}</div>
        <div class="field" style="left: 55mm; top: 155mm;">{{ $report->placenta }}</div>
        <div class="field" style="left: 155mm; top: 157.5mm;">{{ $report->placenta_grade }}</div>
        <div class="field" style="left: 80mm; top: 167.5mm;">{{ $report->amniotic_fluid }}</div>
        <div class="field" style="left: 185mm; top: 167.5mm;">{{ $report->presentation }}</div>

        {{-- Anatomy checkmarks --}}
        @if ($report->lt_ventricular)
            <div class="field" style="left: 112.5mm; top: 190mm;">X</div>
        @else
            <div class="field" style="left: 155mm; top: 190mm;">X</div>
        @endif

        @if ($report->bpd_level)
            <div class="field" style="left: 112.5mm; top: 195mm;">X</div>
        @else
            <div class="field" style="left: 155mm; top: 195mm;">X</div>
        @endif

        @if ($report->feral_stomach)
            <div class="field" style="left: 112.5mm; top: 200mm;">X</div>
        @else
            <div class="field" style="left: 155mm; top: 200mm;">X</div>
        @endif

        @if ($report->kidneys)
            <div class="field" style="left: 112.5mm; top: 207.5mm;">X</div>
        @else
            <div class="field" style="left: 155mm; top: 207.5mm;">X</div>
        @endif

        @if ($report->bladder)
            <div class="field" style="left: 112.5mm; top: 215mm;">X</div>
        @else
            <div class="field" style="left: 155mm; top: 215mm;">X</div>
        @endif

        @if ($report->spine)
            <div class="field" style="left: 112.5mm; top: 220mm;">X</div>
        @else
            <div class="field" style="left: 155mm; top: 220mm;">X</div>
        @endif

        {{-- Biophysical profile --}}
        @if ($report->bpp?->value === 'poor')
            <div class="field" style="left: 70mm; top: 230mm;">X</div>
        @elseif ($report->bpp?->value === 'normal')
            <div class="field" style="left: 117.5mm; top: 230mm;">X</div>
        @elseif ($report->bpp?->value === 'good')
            <div class="field" style="left: 175mm; top: 230mm;">X</div>
        @endif

        {{-- Conclusion --}}
        <div class="field" style="left: 50mm; top: 240mm;">{{ $report->conclusion_line1 }}</div>
        <div class="field" style="left: 25mm; top: 250mm;">{{ $report->conclusion_line2 }}</div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
