<span @class(['flagged' => $row['flag']])>{{ $row['value'] }}</span>@if ($row['flag'])<span class="flag">{{ $row['flag'] === \App\Services\LabReportBuilder::FLAG_HIGH ? 'H' : 'L' }}</span>@endif
