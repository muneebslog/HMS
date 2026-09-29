{{--
    Public medical certificate verification page (QR on the printed certificate). No login;
    found only by the certificate's random token. Shows just enough to confirm it is genuine.

    @var \App\Models\MedicalCertificate $certificate
--}}
@php
    $isValid = ! $certificate->isVoided();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('Certificate Verification') }} — {{ config('hospital.name') }}</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; background: #f4f4f5; color: #18181b; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; line-height: 1.45; }
            .page { max-width: 560px; margin: 0 auto; padding: 24px 16px; }
            .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, .08); overflow: hidden; }
            .head { padding: 20px; border-bottom: 1px solid #e4e4e7; }
            .head h1 { margin: 0; font-size: 18px; text-transform: uppercase; letter-spacing: .03em; }
            .head p { margin: 4px 0 0; font-size: 13px; color: #71717a; }
            .status { display: flex; align-items: center; gap: 10px; padding: 16px 20px; font-weight: 700; }
            .status.valid { background: #ecfdf5; color: #047857; }
            .status.void { background: #fef2f2; color: #b91c1c; }
            dl { margin: 0; padding: 8px 20px 20px; }
            .row { display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-bottom: 1px solid #f4f4f5; font-size: 14px; }
            dt { color: #71717a; }
            dd { margin: 0; font-weight: 600; text-align: right; }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="card">
                <div class="head">
                    <h1>{{ config('hospital.name') }}</h1>
                    <p>{{ __('Medical certificate verification') }}</p>
                </div>

                <div @class(['status', 'valid' => $isValid, 'void' => ! $isValid])>
                    @if ($isValid)
                        &#10004; {{ __('This certificate is genuine and valid.') }}
                    @else
                        &#10006; {{ __('This certificate has been cancelled by the hospital.') }}
                    @endif
                </div>

                <dl>
                    <div class="row"><dt>{{ __('Certificate No.') }}</dt><dd>{{ $certificate->serial_no }}</dd></div>
                    <div class="row"><dt>{{ __('Type') }}</dt><dd>{{ $certificate->type->title() }}</dd></div>
                    <div class="row"><dt>{{ __('Patient') }}</dt><dd>{{ $certificate->patient_title }} {{ mb_strtoupper($certificate->patient_name) }}</dd></div>
                    <div class="row"><dt>{{ __('Issued on') }}</dt><dd>{{ $certificate->issued_at->format('d-m-Y') }}</dd></div>
                    <div class="row">
                        <dt>{{ __('Period') }}</dt>
                        <dd>
                            {{ $certificate->start_date->format('d-m-Y') }}
                            @if ($certificate->end_date)
                                {{ __('to') }} {{ $certificate->end_date->format('d-m-Y') }}
                            @endif
                        </dd>
                    </div>
                    <div class="row"><dt>{{ __('Doctor') }}</dt><dd>{{ $certificate->doctor_name }}</dd></div>
                </dl>
            </div>
        </div>
    </body>
</html>
