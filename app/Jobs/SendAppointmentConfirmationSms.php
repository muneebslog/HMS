<?php

namespace App\Jobs;

use App\Models\Doctor;
use App\Models\SmsLog;
use App\Services\SmsService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAppointmentConfirmationSms implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $phone,
        public Doctor $doctor,
        public int $tokenNumber,
        public ?CarbonInterface $estimatedTime,
        public int $smsLogId,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(SmsService $smsService): void
    {
        $log = SmsLog::find($this->smsLogId);

        if ($log === null) {
            return;
        }

        $smsService->sendAppointmentConfirmation(
            $this->phone,
            $this->doctor,
            $this->tokenNumber,
            $this->estimatedTime,
            $log,
        );
    }
}
