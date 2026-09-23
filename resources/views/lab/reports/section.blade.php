@php
    $layoutView = $section['layout']->view();

    if ($section['layout'] === \App\Enums\LabReportLayout::Custom) {
        $customView = filled($section['custom_template']) ? 'lab.reports.custom.'.$section['custom_template'] : null;
        $layoutView = $customView && view()->exists($customView) ? $customView : \App\Enums\LabReportLayout::Table->view();
    }
@endphp

<div @class(['test', 'new-page' => $startsNewPage ?? false])>
    <h2 class="test-title">{{ $section['title'] }}</h2>

    @include($layoutView, ['section' => $section])

    @if ($section['note'])
        <div class="note">{{ $section['note'] }}</div>
    @endif

    @if ($section['comment'] && $section['layout'] !== \App\Enums\LabReportLayout::Narrative)
        <div class="comment">{{ $section['comment'] }}</div>
    @endif
</div>
