@php
    $builder = app(\App\Services\LabReportBuilder::class);
    $gridRows = collect($section['rows'])->filter(fn (array $row) => count($row['options']) > 1);
    $otherRows = collect($section['rows'])->reject(fn (array $row) => count($row['options']) > 1);
    $columns = $gridRows->isNotEmpty() ? $builder->gridCells($gridRows->first())['columns'] : [];
@endphp

@if ($gridRows->isNotEmpty())
    <table class="grid">
        <thead>
            <tr>
                <th class="label"></th>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($gridRows as $row)
                @php($cells = $builder->gridCells($row))
                <tr>
                    <td class="label">{{ $row['field'] }}</td>
                    @foreach ($cells['columns'] as $index => $column)
                        <td>{{ $index < $cells['filled'] ? '+' : '–' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@foreach ($otherRows as $row)
    <div class="compact-row">
        <span>{{ $row['field'] }}</span>
        <span class="leader"></span>
        <span class="value">@include('lab.reports.partials.value', ['row' => $row])</span>
    </div>
@endforeach
