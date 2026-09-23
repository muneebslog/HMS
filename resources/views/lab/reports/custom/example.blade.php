{{--
    Example custom lab report template.

    Copy this file, rename it (e.g. "semen-analysis.blade.php") and set the
    test's custom template to that name without ".blade.php".

    Available: $section with keys title, note, comment, show_ranges,
    groups (heading + rows) and rows. Each row has field, unit, value,
    range, flag ('high' | 'low' | null) and options.
--}}
@foreach ($section['rows'] as $row)
    <div class="pair">
        <span class="label">{{ $row['field'] }}</span>
        <span class="value">@include('lab.reports.partials.value', ['row' => $row])</span>
    </div>
@endforeach
