@include('lab.reports.layouts.two-columns', ['section' => $section])

@if ($section['comment'])
    <div class="comment large">{{ $section['comment'] }}</div>
@endif
