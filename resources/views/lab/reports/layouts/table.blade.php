@foreach ($section['groups'] as $group)
    @if ($group['heading'])
        <div class="group-heading">{{ $group['heading'] }}</div>
    @endif

    <table class="results">
        @if ($loop->first)
            <thead>
                <tr>
                    <th style="width: 38%">{{ __('Test') }}</th>
                    <th style="width: 20%">{{ __('Result') }}</th>
                    <th style="width: 16%">{{ __('Unit') }}</th>
                    @if ($section['show_ranges'])
                        <th>{{ __('Normal Range') }}</th>
                    @endif
                </tr>
            </thead>
        @endif
        <tbody>
            @foreach ($group['rows'] as $row)
                <tr>
                    <td style="width: 38%">{{ $row['field'] }}</td>
                    <td style="width: 20%">@include('lab.reports.partials.value', ['row' => $row])</td>
                    <td style="width: 16%">{{ $row['unit'] }}</td>
                    @if ($section['show_ranges'])
                        <td>{{ $row['range'] }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach
