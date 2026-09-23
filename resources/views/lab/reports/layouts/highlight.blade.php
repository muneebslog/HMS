<div class="highlight">
    {{ collect($section['rows'])->map(fn (array $row) => trim($row['value'].' '.($row['unit'] ?? '')))->implode(' ') }}
</div>
