{{--
    Printable medical certificate (A4).

    @var \App\Models\MedicalCertificate $certificate
--}}
@php
    use App\Enums\MedicalCertificateType;

    $date = fn (?\Carbon\CarbonInterface $value) => $value?->format('d-m-Y') ?? '-';
    $time = fn (string $value) => \Illuminate\Support\Carbon::createFromFormat('H:i', $value)->format('g:i A');
    $showDiagnosis = $certificate->show_diagnosis && filled($certificate->diagnosis);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $certificate->type->title() }} — {{ $certificate->patient_name }}</title>
        <style>
            * { box-sizing: border-box; }
            @page { size: A4; margin: 12mm; }
            body { margin: 0; padding: 24px; background: #e5e7eb; color: #111; font-family: Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.4; }

            .sheet { position: relative; max-width: 210mm; min-height: 297mm; margin: 0 auto; padding: 16mm 14mm; background: #fff; box-shadow: 0 4px 20px rgba(0, 0, 0, .15); display: flex; flex-direction: column; overflow: hidden; }
            .sheet-body { flex: 1 0 auto; display: flex; flex-direction: column; padding-bottom: 90px; }

            .header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; border-bottom: 2px solid #111; padding-bottom: 14px; margin-bottom: 50px; }
            .facility-name { margin: 0; font-size: 17pt; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; line-height: 1.2; }
            .tagline { margin: 4px 0 0; font-size: 9.5pt; color: #444; font-style: italic; }
            .address { margin: 4px 0 0; font-size: 8.5pt; color: #555; }
            .header-right { text-align: right; flex-shrink: 0; }
            .issued { margin: 0 0 6px; font-size: 8.5pt; color: #555; }
            .barcode svg { display: block; margin-left: auto; }
            .barcode-label { margin: 2px 0 0; font-size: 8pt; font-family: Consolas, "Courier New", monospace; letter-spacing: .08em; }

            .doc-title { margin: 0 0 50px; text-align: center; font-size: 15pt; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; text-decoration: underline; }

            .upper { text-transform: uppercase; }

            .ref-line { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; font-size: 9.5pt; color: #555; margin-bottom: 60px; }
            .ref-line b { color: #111; }
            .statement { font-size: 14pt; line-height: 2.6; text-align: justify; margin: 10px 0 40px; }
            .statement p { margin: 0 0 12px; }
            .statement b { border-bottom: 1px dotted #333; padding: 0 2px; }

            .footer-block { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-top: auto; padding-top: 60px; }
            .qr { text-align: center; font-size: 7.5pt; color: #555; width: 120px; }
            .qr svg { display: block; width: 96px; height: 96px; margin: 0 auto 4px; }
            .stamp { width: 110px; height: 110px; border: 2px dashed #bbb; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #aaa; font-size: 8pt; text-align: center; }
            .signature { text-align: center; min-width: 220px; }
            .signature .sig { height: 36px; }
            .signature .line { border-top: 1px solid #333; padding-top: 6px; }
            .signature .dr { font-weight: 700; }

            .note { margin-top: 30px; font-size: 8.5pt; color: #555; font-style: italic; text-align: center; }
            .contact { margin-top: 12px; border-top: 1px solid #ccc; padding-top: 10px; font-size: 9pt; color: #333; text-align: center; }
            .contact p { margin: 0; }

            .no-print { max-width: 210mm; margin: 16px auto 0; text-align: center; }
            .no-print button { padding: 8px 16px; font-size: 11pt; cursor: pointer; }

            @media print {
                body { background: #fff; padding: 0; }
                .sheet { box-shadow: none; padding: 0; min-height: calc(297mm - 24mm); }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body onload="window.print()">
        <div class="sheet">
            <div class="sheet-body">
                <div class="header">
                    <div>
                        <h1 class="facility-name">{{ config('hospital.name') }}</h1>
                        @if (filled(config('hospital.tagline')))
                            <p class="tagline">{{ config('hospital.tagline') }}</p>
                        @endif
                        @if (filled(config('hospital.address')))
                            <p class="address">{{ config('hospital.address') }}</p>
                        @endif
                    </div>
                    <div class="header-right">
                        <p class="issued">{{ __('Issued') }} {{ $certificate->issued_at->format('d M Y, g:i A') }}</p>
                        <div class="barcode">
                            {!! \App\Support\Code39Barcode::svg($certificate->serial_no, 36, 1.2) !!}
                        </div>
                        <p class="barcode-label">{{ $certificate->serial_no }}</p>
                    </div>
                </div>

                <p class="doc-title">{{ $certificate->type->title() }}</p>

                <div class="ref-line">
                    <span>{{ __('MR No.') }} <b>{{ $certificate->mrn ?: '-' }}</b></span>
                    <span>{{ __('CNIC') }} <b>{{ $certificate->cnic ?: '-' }}</b></span>
                    <span>{{ __('Date') }} <b>{{ $certificate->issued_at->format('d-m-Y') }}</b></span>
                </div>

                @php
                    $patientLine = new \Illuminate\Support\HtmlString(
                        '<b>'.e($certificate->patient_title).'</b> <b class="upper">'.e($certificate->patient_name).'</b>'
                        .(filled($certificate->guardian_name) ? ' '.e($certificate->relation).' <b class="upper">'.e($certificate->guardian_name).'</b>' : '')
                        .($certificate->age !== null ? ', '.e(__('age')).' <b>'.e($certificate->age).'</b> '.e(__('years')).',' : '')
                    );
                @endphp

                <div class="statement">
                    @switch($certificate->type)
                        @case(MedicalCertificateType::SickLeave)
                            <p>
                                {{ __('This is to certify that') }} {{ $patientLine }}
                                @if ($showDiagnosis)
                                    {{ __('is suffering from') }} <b>{{ $certificate->diagnosis }}</b> {{ __('and') }}
                                @endif
                                {{ __('is not feeling well.') }}
                                {{ __('I advise :pronoun complete bed rest from', ['pronoun' => $certificate->objectPronoun()]) }}
                                <b>{{ $date($certificate->start_date) }}</b> {{ __('to') }} <b>{{ $date($certificate->end_date) }}</b>.
                            </p>
                            @break

                        @case(MedicalCertificateType::Fitness)
                            <p>
                                {{ __('This is to certify that') }} {{ $patientLine }}
                                {{ __('was under my treatment') }}@if ($showDiagnosis) {{ __('for') }} <b>{{ $certificate->diagnosis }}</b>@endif
                                {{ __('from') }} <b>{{ $date($certificate->start_date) }}</b> {{ __('to') }} <b>{{ $date($certificate->end_date) }}</b>.
                                {{ __(':pronoun has now recovered and is medically fit to resume :possessive normal duties / studies from', ['pronoun' => $certificate->subjectPronoun(), 'possessive' => $certificate->possessivePronoun()]) }}
                                <b>{{ $date($certificate->resume_date) }}</b>.
                            </p>
                            @break

                        @case(MedicalCertificateType::Maternity)
                            <p>
                                {{ __('This is to certify that') }} {{ $patientLine }}
                                {{ __('is under my antenatal care at this hospital.') }}
                                @if ($certificate->gestation_weeks)
                                    {{ __(':pronoun is', ['pronoun' => $certificate->subjectPronoun()]) }} <b>{{ __(':weeks weeks', ['weeks' => $certificate->gestation_weeks]) }}</b> {{ __('pregnant') }}@if ($certificate->expected_delivery_date) {{ __('and :possessive expected date of delivery is', ['possessive' => $certificate->possessivePronoun()]) }} <b>{{ $date($certificate->expected_delivery_date) }}</b>@endif.
                                @endif
                                {{ __('I advise :pronoun maternity leave from', ['pronoun' => $certificate->objectPronoun()]) }}
                                <b>{{ $date($certificate->start_date) }}</b> {{ __('to') }} <b>{{ $date($certificate->end_date) }}</b>.
                            </p>
                            @break

                        @case(MedicalCertificateType::Attendance)
                            <p>
                                {{ __('This is to certify that') }} {{ $patientLine }}
                                {{ __('attended the OPD of this hospital on') }} <b>{{ $date($certificate->start_date) }}</b>
                                @if (filled($certificate->time_from) && filled($certificate->time_to))
                                    {{ __('from') }} <b>{{ $time($certificate->time_from) }}</b> {{ __('to') }} <b>{{ $time($certificate->time_to) }}</b>
                                @endif
                                {{ __('for medical consultation') }}@if ($showDiagnosis) {{ __('regarding') }} <b>{{ $certificate->diagnosis }}</b>@endif.
                                {{ __('This certificate is issued on :possessive request.', ['possessive' => $certificate->possessivePronoun()]) }}
                            </p>
                            @break
                    @endswitch
                </div>

                <div class="footer-block">
                    <div class="qr">
                        {!! $certificate->verificationQrSvg() !!}
                        {{ __('Scan to verify') }}<br>{{ __('authenticity') }}
                    </div>
                    <div class="stamp">{{ __('Hospital') }}<br>{{ __('Stamp') }}</div>
                    <div class="signature">
                        <div class="sig"></div>
                        <div class="line">
                            <div class="dr">{{ $certificate->doctor_name }}</div>
                        </div>
                    </div>
                </div>

                <p class="note">
                    {{ __('This certificate is computer generated and valid only with the doctor\'s signature and hospital stamp.') }}
                    {{ __('Verify at') }} {{ $certificate->verificationUrl() }}
                </p>
            </div>

            <div class="contact">
                <p>
                    {{ collect([config('hospital.address'), config('hospital.phone'), config('hospital.email')])->filter()->join(' · ') }}
                </p>
            </div>
        </div>

        <div class="no-print">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        </div>
    </body>
</html>
