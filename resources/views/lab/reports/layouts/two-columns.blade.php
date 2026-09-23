@foreach ($section['groups'] as $group)
    @if ($group['heading'])
        <div class="group-heading">{{ $group['heading'] }}</div>
    @endif

    <div class="pairs">
        @foreach ($group['rows'] as $row)
            <div class="pair">
                <span class="label">{{ $row['field'] }}</span>
                <span class="value">
                    @include('lab.reports.partials.value', ['row' => $row])
                    @if ($row['unit'])
                        <span style="font-weight: 400; color: #555;">{{ $row['unit'] }}</span>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
@endforeach
