@props([
    'ultrasound',
    'compact' => false,
])

{{-- Read-only view of a GyneUltrasound. "compact" is the one-line card version. --}}
@php
    $gestation = $ultrasound->gestationalAgeLabel();
    $edd = $ultrasound->expectedDeliveryDate();
    $concerns = $ultrasound->concernLabels();
@endphp

@if ($compact)
    <div {{ $attributes->class('flex flex-col gap-1') }}>
        <p class="text-sm font-semibold text-zinc-900">
            <span class="text-xs font-semibold uppercase tracking-wide text-sky-700">{{ __('USG') }}</span>
            {{ collect([
                $ultrasound->fetusCountLabel(),
                $gestation,
                $ultrasound->fetal_heart_rate ? __('FHR :rate', ['rate' => $ultrasound->fetal_heart_rate]) : null,
                $edd ? __('EDD :date', ['date' => $edd->format('d M')]) : null,
            ])->filter()->implode(' · ') ?: __('Report entered') }}
        </p>
        @if ($concerns !== [])
            <div class="flex flex-wrap gap-1">
                @foreach ($concerns as $label)
                    <span class="rounded-sm bg-amber-200/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">{{ $label }}</span>
                @endforeach
            </div>
        @endif
    </div>
@else
    <dl {{ $attributes->class('grid grid-cols-2 gap-x-4 gap-y-3 text-sm') }}>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Babies') }}</dt>
            <dd class="font-semibold">{{ $ultrasound->fetusCountLabel() ?? '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('GA by scan') }}</dt>
            <dd class="font-semibold">{{ $gestation ?? '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Heart rate (FHR)') }}</dt>
            <dd @class(['font-semibold', 'text-amber-700 dark:text-amber-400' => $ultrasound->hasAbnormalHeartRate()])>
                {{ $ultrasound->fetal_heart_rate ? __(':rate bpm', ['rate' => $ultrasound->fetal_heart_rate]) : '–' }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Weight (EFW)') }}</dt>
            <dd class="font-semibold">{{ $ultrasound->efw_grams ? __(':grams g', ['grams' => number_format($ultrasound->efw_grams)]) : '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Presentation') }}</dt>
            <dd class="font-semibold">{{ $ultrasound->presentation?->label() ?? '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Placenta') }}</dt>
            <dd @class(['font-semibold', 'text-amber-700 dark:text-amber-400' => $ultrasound->placenta?->isConcerning()])>
                {{ $ultrasound->placenta?->label() ?? '–' }}
            </dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Liquor') }}</dt>
            <dd @class(['font-semibold', 'text-amber-700 dark:text-amber-400' => $ultrasound->liquor?->isConcerning()])>
                {{ $ultrasound->liquor?->label() ?? '–' }}
                @if ($ultrasound->afi !== null)
                    <span class="font-normal text-zinc-500">({{ __('AFI :afi cm', ['afi' => $ultrasound->afi]) }})</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('EDD by scan') }}</dt>
            <dd class="font-semibold">{{ $edd?->format('d M Y') ?? '–' }}</dd>
        </div>
        <div class="col-span-2">
            <dt class="text-xs text-zinc-500">{{ __('Impression') }}</dt>
            <dd class="font-semibold whitespace-pre-line">{{ $ultrasound->impression ?? '–' }}</dd>
        </div>
    </dl>
@endif
