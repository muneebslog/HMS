<?php

namespace App\Console\Commands;

use App\Services\OldLabResultsImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lab:import-old-results
    {dump? : Path to the old lab software SQL dump}
    {--since= : Only HMS lab cases created on or after this date (default: one month ago)}
    {--apply : Write the results (without this it is a dry run)}
    {--rollback : Remove previously imported results instead of importing}
    {--details : List every test that would be imported}')]
#[Description('Import results from the old lab software dump into HMS lab results (dry run unless --apply).')]
class ImportOldLabResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OldLabResultsImporter $importer): int
    {
        if ($this->option('rollback')) {
            return $this->rollback($importer);
        }

        $dump = $this->argument('dump');

        if (blank($dump)) {
            $this->error('Give the path to the old lab SQL dump.');

            return self::FAILURE;
        }

        $this->info('Reading dump...');
        $old = $importer->parseDump($dump);

        foreach ($old as $table => $rows) {
            $this->line(sprintf('  %-16s %d rows', $table, count($rows)));
        }

        try {
            $since = filled($this->option('since'))
                ? CarbonImmutable::parse($this->option('since'))->startOfDay()
                : CarbonImmutable::now()->subMonth()->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid --since date. Use e.g. 2026-08-24.');

            return self::FAILURE;
        }

        $this->info('Only lab cases created since '.$since->format('d M Y').'.');

        $plan = $importer->plan($old, $since);

        $this->newLine();
        $this->info(count($plan['items']).' test(s) ready to import, '.collect($plan['items'])->sum(fn ($item) => count($item['values'])).' value(s).');

        if ($this->option('details')) {
            $this->table(
                ['Receipt', 'Patient', 'Test', 'Completed', 'Values', 'Notes'],
                collect($plan['items'])->map(fn ($item) => [
                    $item['invoice_number'],
                    $item['patient'],
                    $item['test'],
                    $item['completed_at']->format('d M Y H:i'),
                    count($item['values']),
                    implode('; ', $item['notes']),
                ])->all(),
            );
        }

        $this->newLine();
        $this->info('Skipped:');
        foreach ($plan['skipped'] as $reason => $labels) {
            $this->line(sprintf('  %-58s %d', $reason, count($labels)));
            foreach (array_slice($labels, 0, 5) as $label) {
                $this->line("      {$label}");
            }
        }

        $this->printTally('Values translated to HMS options:', $plan['translated']);
        $this->printTally('Values kept as written (not an HMS option):', $plan['kept_as_written']);
        $this->printTally('Values left out (not a valid number):', $plan['dropped_values']);

        $notes = collect($plan['items'])->flatMap(fn ($item) => $item['notes'])->countBy();
        $this->printTally('Field notes:', $notes->all());

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Dry run — nothing was written. Re-run with --apply to import.');

            return self::SUCCESS;
        }

        $imported = $importer->apply($plan);

        $this->newLine();
        $this->info("Imported results for {$imported} test(s).");

        return self::SUCCESS;
    }

    /**
     * Remove previously imported results (dry run unless --apply).
     */
    private function rollback(OldLabResultsImporter $importer): int
    {
        $count = $importer->rollbackCount();

        if (! $this->option('apply')) {
            $this->warn("Dry run — {$count} imported test(s) would be rolled back. Re-run with --rollback --apply.");

            return self::SUCCESS;
        }

        $this->info('Rolled back '.$importer->rollback().' imported test(s).');

        return self::SUCCESS;
    }

    /**
     * Print a tally of value changes.
     *
     * @param  array<string, int>  $tally
     */
    private function printTally(string $heading, array $tally): void
    {
        if ($tally === []) {
            return;
        }

        arsort($tally);
        $this->newLine();
        $this->info($heading);

        foreach ($tally as $label => $count) {
            $this->line(sprintf('  %-58s %d', $label, $count));
        }
    }
}
