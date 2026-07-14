<?php

namespace App\Console\Commands;

use App\Jobs\SendLabCaseToLab;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class RetryFailedLabCases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lab:retry-failed-cases';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry failed lab-case sync jobs every 30 minutes.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jobClass = str_replace('\\', '\\\\', SendLabCaseToLab::class);

        $ids = DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$jobClass.'%')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            $this->info('No failed lab-case sync jobs to retry.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            $this->info("Retrying failed lab-case job #{$id}.");
            Artisan::call('queue:retry', ['id' => $id]);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
