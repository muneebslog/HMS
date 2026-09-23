{{-- Disclaimer, signatories and address band, pinned to the bottom of every sheet. --}}
<div class="page-footer">
    @if (filled($lab['disclaimer']))
        <div class="disclaimer">{{ $lab['disclaimer'] }}</div>
    @endif

    @if (! empty($lab['signatories']))
        <div class="signatories">
            @foreach ($lab['signatories'] as $signatory)
                <div>
                    <div class="signatory-name">{{ $signatory['name'] }}</div>
                    @if (filled($signatory['qualification'] ?? null))
                        <div class="signatory-meta">{{ $signatory['qualification'] }}</div>
                    @endif
                    @if (filled($signatory['title'] ?? null))
                        <div class="signatory-meta">{{ $signatory['title'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="address-band">
        <div>{{ collect([$lab['address'], $lab['phone']])->filter()->implode(', ') }}</div>
        @if (filled($lab['website']))
            <div class="website">{{ __('Website') }}: {{ $lab['website'] }}</div>
        @endif
    </div>
</div>
