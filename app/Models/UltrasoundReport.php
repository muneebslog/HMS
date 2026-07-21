<?php

namespace App\Models;

use App\Enums\UltrasoundBiophysicalProfile;
use Database\Factories\UltrasoundReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UltrasoundReport extends Model
{
    /** @use HasFactory<UltrasoundReportFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'queue_token_id',
        'patient_id',
        'doctor_id',
        'service_queue_id',
        'report_date',
        'name',
        'age',
        'fetus_status',
        'bpd_meas',
        'bpd_age',
        'femur_meas',
        'femur_age',
        'ac_meas',
        'ac_age',
        'crl_meas',
        'crl_age',
        'gest_age',
        'edd',
        'heart_motion',
        'placenta',
        'placenta_grade',
        'amniotic_fluid',
        'presentation',
        'lt_ventricular',
        'bpd_level',
        'feral_stomach',
        'kidneys',
        'bladder',
        'spine',
        'bpp',
        'conclusion_line1',
        'conclusion_line2',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'lt_ventricular' => 'boolean',
            'bpd_level' => 'boolean',
            'feral_stomach' => 'boolean',
            'kidneys' => 'boolean',
            'bladder' => 'boolean',
            'spine' => 'boolean',
            'bpp' => UltrasoundBiophysicalProfile::class,
        ];
    }

    /**
     * Get the queue token this report belongs to.
     *
     * @return BelongsTo<QueueToken, $this>
     */
    public function queueToken(): BelongsTo
    {
        return $this->belongsTo(QueueToken::class);
    }

    /**
     * Get the patient this report belongs to.
     *
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the doctor this report belongs to.
     *
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the service queue this report belongs to.
     *
     * @return BelongsTo<ServiceQueue, $this>
     */
    public function serviceQueue(): BelongsTo
    {
        return $this->belongsTo(ServiceQueue::class);
    }
}
