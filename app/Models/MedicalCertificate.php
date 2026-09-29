<?php

namespace App\Models;

use App\Enums\MedicalCertificateType;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Database\Factories\MedicalCertificateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MedicalCertificate extends Model
{
    /** @use HasFactory<MedicalCertificateFactory> */
    use HasFactory;

    /**
     * Titles offered for the patient, with the gender they imply.
     *
     * @var array<string, string>
     */
    public const TITLES = [
        'Mr.' => 'male',
        'Master' => 'male',
        'Mrs.' => 'female',
        'Miss' => 'female',
        'Baby' => 'female',
    ];

    /**
     * Relation prefixes printed before the guardian name.
     *
     * @var list<string>
     */
    public const RELATIONS = ['s/o', 'd/o', 'w/o'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'patient_id',
        'doctor_id',
        'patient_title',
        'patient_name',
        'gender',
        'relation',
        'guardian_name',
        'age',
        'mrn',
        'cnic',
        'diagnosis',
        'show_diagnosis',
        'start_date',
        'end_date',
        'resume_date',
        'time_from',
        'time_to',
        'gestation_weeks',
        'expected_delivery_date',
        'doctor_name',
        'issued_by',
        'issued_at',
        'print_count',
        'last_printed_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MedicalCertificateType::class,
            'age' => 'integer',
            'show_diagnosis' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'resume_date' => 'date',
            'gestation_weeks' => 'integer',
            'expected_delivery_date' => 'date',
            'issued_at' => 'datetime',
            'print_count' => 'integer',
            'last_printed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Give every certificate a verification token and a serial number.
     */
    protected static function booted(): void
    {
        static::creating(function (MedicalCertificate $certificate) {
            if (blank($certificate->verification_token)) {
                $certificate->verification_token = Str::random(24);
            }
        });

        static::created(function (MedicalCertificate $certificate) {
            if (blank($certificate->serial_no)) {
                $certificate->serial_no = 'MC-'.$certificate->issued_at->format('Y').'-'.str_pad((string) $certificate->id, 6, '0', STR_PAD_LEFT);
                $certificate->saveQuietly();
            }
        });
    }

    /**
     * Get the patient this certificate was issued for.
     *
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the doctor who signs this certificate.
     *
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the user who issued this certificate.
     *
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Get the user who voided this certificate.
     *
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Scope to certificates that have not been voided.
     *
     * @param  Builder<MedicalCertificate>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * Whether the certificate has been voided.
     */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Whether the certificate is written about a male patient.
     */
    public function isMale(): bool
    {
        return $this->gender === 'male';
    }

    /**
     * The pronoun used as a subject ("He"/"She").
     */
    public function subjectPronoun(): string
    {
        return $this->isMale() ? __('He') : __('She');
    }

    /**
     * The pronoun used as an object ("him"/"her").
     */
    public function objectPronoun(): string
    {
        return $this->isMale() ? __('him') : __('her');
    }

    /**
     * The possessive pronoun ("his"/"her").
     */
    public function possessivePronoun(): string
    {
        return $this->isMale() ? __('his') : __('her');
    }

    /**
     * The public page that confirms this certificate is genuine (QR link on the print).
     * Always built on APP_URL: staff reach the server by its LAN address, which would not work for others.
     */
    public function verificationUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('medical-certificates.verify', $this->verification_token, false);
    }

    /**
     * Render the verification link as an inline QR code SVG.
     */
    public function verificationQrSvg(int $size = 96): string
    {
        $svg = (new Writer(
            new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd)
        ))->writeString($this->verificationUrl());

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * Number of calendar days covered by the start and end dates.
     */
    public function totalDays(): ?int
    {
        if ($this->end_date === null) {
            return null;
        }

        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Build certificate details from a patient's record, for pre-filling the form.
     *
     * @return array{patient_title: string, patient_name: string, gender: string, relation: string, guardian_name: ?string, age: ?int, mrn: ?string, cnic: ?string, diagnosis: ?string, doctor_id: ?int}
     */
    public static function detailsFromPatient(Patient $patient): array
    {
        $isFemale = $patient->gender === 'female';
        $isMarried = $isFemale && filled($patient->husband_name);
        $isChild = $patient->age !== null && $patient->age < 13;

        $title = match (true) {
            ! $isFemale => $isChild ? 'Master' : 'Mr.',
            $isMarried => 'Mrs.',
            default => $isChild ? 'Baby' : 'Miss',
        };

        $latestOrder = MedicationOrder::query()
            ->where('patient_id', $patient->id)
            ->with('symptoms')
            ->latest()
            ->first();

        $diagnosis = filled($latestOrder?->complaint_or_diagnosis)
            ? $latestOrder->complaint_or_diagnosis
            : $latestOrder?->symptoms->pluck('name')->join(', ');

        $doctorId = $latestOrder?->doctor_id ?? InvoiceItem::query()
            ->whereNotNull('doctor_id')
            ->whereHas('invoice', fn (Builder $invoice) => $invoice->where('patient_id', $patient->id))
            ->latest()
            ->value('doctor_id');

        return [
            'patient_title' => $title,
            'patient_name' => $patient->name,
            'gender' => $isFemale ? 'female' : 'male',
            'relation' => match (true) {
                $isMarried => 'w/o',
                $isFemale => 'd/o',
                default => 's/o',
            },
            'guardian_name' => $patient->husband_name,
            'age' => $patient->age,
            'mrn' => $patient->mrn,
            'cnic' => $patient->cnic,
            'diagnosis' => filled($diagnosis) ? $diagnosis : null,
            'doctor_id' => $doctorId,
        ];
    }
}
