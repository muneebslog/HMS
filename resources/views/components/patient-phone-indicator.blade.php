@props([
    'patient' => null,
    'linked' => null,
])

@php
    $hasPhone = $linked ?? $patient?->hasLinkedPhone() ?? false;
@endphp

<span
    {{ $attributes->class([
        'inline-block size-2.5 shrink-0 rounded-full',
        'bg-green-500' => $hasPhone,
        'bg-orange-500' => ! $hasPhone,
    ]) }}
    title="{{ $hasPhone ? __('Phone linked') : __('No phone linked') }}"
    aria-label="{{ $hasPhone ? __('Phone linked') : __('No phone linked') }}"
></span>
