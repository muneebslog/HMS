@foreach ($section['groups'] as $group)
    @if ($group['heading'])
        <div class="group-heading">{{ $group['heading'] }}</div>
    @endif

    @foreach ($group['rows'] as $row)
        <div class="compact-row">
            <span>{{ $row['field'] }}</span>
            <span class="leader"></span>
            <span class="value">
                @include('lab.reports.partials.value', ['row' => $row])
                @if ($row['unit'])
                    {{ $row['unit'] }}
                @endif
            </span>
            @if ($section['show_ranges'] && $row['range'])
                <span class="range">({{ $row['range'] }})</span>
            @endif
        </div>
    @endforeach
@endforeach
