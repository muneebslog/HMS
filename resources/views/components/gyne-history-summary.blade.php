@props([
    'history',
    'compact' => false,
])

{{-- Read-only view of a GyneHistory. "compact" is the one-line card version. --}}
@php
    $obstetric = $history->obstetricSummary();
    $gestation = $history->gestationalAgeLabel();
    $edd = $history->expectedDeliveryDate();
    $problems = $history->problemLabels();
    $operations = $history->operationLabels();
@endphp

@if ($compact)
    <div {{ $attributes->class('flex flex-col gap-1') }}>
        <p class="text-sm font-semibold text-zinc-900">
            {{ collect([$obstetric, $gestation, $edd ? __('EDD :date', ['date' => $edd->format('d M')]) : null])->filter()->implode(' · ') ?: __('History taken') }}
        </p>
        @if ($problems !== [] || $operations !== [])
            <div class="flex flex-wrap gap-1">
                @foreach ($operations as $label)
                    <span class="rounded-sm bg-rose-200/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-900">{{ $label }}</span>
                @endforeach
                @foreach ($problems as $label)
                    <span class="rounded-sm bg-amber-200/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">{{ $label }}</span>
                @endforeach
            </div>
        @endif
    </div>
@else
    <dl {{ $attributes->class('grid grid-cols-2 gap-x-4 gap-y-3 text-sm') }}>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Gravida') }}</dt>
            <dd class="font-semibold">{{ $history->gravida ?? '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Deliveries (para)') }}</dt>
            <dd class="font-semibold">{{ $history->para ?? '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Babies alive') }}</dt>
            <dd class="font-semibold">{{ $history->living_children ?? '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Miscarriages') }}</dt>
            <dd class="font-semibold">{{ $history->miscarriages ?? '–' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Married since') }}</dt>
            <dd class="font-semibold">
                @if ($history->married_since)
                    {{ $history->married_since->format('M Y') }}
                    <span class="font-normal text-zinc-500">({{ __(':count yrs', ['count' => (int) $history->married_since->diffInYears(now())]) }})</span>
                @else
                    –
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500">{{ __('Last pregnancy') }}</dt>
            <dd class="font-semibold">{{ $history->last_pregnancy_at?->format('M Y') ?? '–' }}</dd>
        </div>
        <div class="col-span-2">
            <dt class="text-xs text-zinc-500">{{ __('LMP') }}</dt>
            <dd class="font-semibold">
                {{ $history->lmp?->format('d M Y') ?? '–' }}
                @if ($gestation)
                    <span class="ms-1 rounded-sm bg-rose-100 px-1.5 py-0.5 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                        {{ $gestation }} · {{ __('EDD :date', ['date' => $edd->format('d M Y')]) }}
                    </span>
                @endif
            </dd>
        </div>
        <div class="col-span-2">
            <dt class="text-xs text-zinc-500">{{ __('Problems') }}</dt>
            <dd class="font-semibold">{{ $problems !== [] ? implode(', ', $problems) : __('None') }}</dd>
        </div>
        <div class="col-span-2">
            <dt class="text-xs text-zinc-500">{{ __('Previous operations') }}</dt>
            <dd class="font-semibold">{{ $operations !== [] ? implode(', ', $operations) : __('None') }}</dd>
        </div>
    </dl>
@endif
