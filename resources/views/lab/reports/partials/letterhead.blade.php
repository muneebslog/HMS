{{-- Lab letterhead and patient box, printed at the top of every sheet. --}}
<div class="letterhead">
    <div>
        <div class="brand">
            <span class="brand-name">{{ $lab['brand'] }}</span>
            <span class="brand-subtitle">{!! nl2br(e(str_replace(' ', "\n", $lab['brand_subtitle']))) !!}</span>
        </div>
        @if (filled($lab['registration']))
            <div class="registration">{{ $lab['registration'] }}</div>
        @endif
    </div>

    @if (filled($header['mrn']))
        <div class="mr">
            <div class="mr-label">{{ __('Medical Record No') }}</div>
            {!! \App\Support\Code39Barcode::svg($header['mrn'], 30, 1) !!}
            <div class="mr-number">{{ $header['mrn'] }}</div>
        </div>
    @endif
</div>

<div class="patient">
    <div class="patient-col">
        <div class="patient-row">
            <span class="label">{{ __('Patient') }}</span>
            <span class="value person-name">{{ $header['patient_name'] }}</span>
        </div>
        <div class="patient-row">
            <span class="label">{{ __('Age / Sex') }}</span>
            <span class="value">{{ $header['age_sex'] ?? '—' }}</span>
        </div>
        <div class="patient-row">
            <span class="label">{{ __('Phone') }}</span>
            <span class="value">{{ $header['phone'] ?? '—' }}</span>
        </div>
    </div>
    <div class="patient-col">
        <div class="patient-row">
            <span class="label">{{ __('Receipt No') }}</span>
            <span class="value">{{ $header['number'] }}</span>
        </div>
        <div class="patient-row">
            <span class="label">{{ __('MR No') }}</span>
            <span class="value">{{ $header['mrn'] ?? '—' }}</span>
        </div>
        <div class="patient-row">
            <span class="label">{{ __('Referred By') }}</span>
            <span class="value">{{ $header['referred_by'] ?? __('Self') }}</span>
        </div>
    </div>
    <div class="patient-col dates">
        <div class="patient-row">
            <span class="label">{{ __('Sample Date') }}</span>
            <span class="value">{{ $header['sample_date'] ?? '—' }}</span>
        </div>
        <div class="patient-row">
            <span class="label">{{ __('Report Date') }}</span>
            <span class="value">{{ $header['report_date'] ?? '—' }}</span>
        </div>
    </div>
</div>
