<?php

namespace App\Models;

use Database\Factories\PartnerLabReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A test found on the partner lab's portal. Once its report is ready, the lab
 * attaches it to one of our outsourced tests.
 */
class PartnerLabReport extends Model
{
    /** @use HasFactory<PartnerLabReportFactory> */
    use HasFactory;

    public const SOURCE_TEST_ZONE = 'testzone';

    /**
     * Partner portal statuses meaning the report can be printed.
     *
     * @var list<string>
     */
    public const READY_STATUSES = ['approved report', 'print', 'delivered report', 'email sent'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'partner_test_id',
        'partner_case_no',
        'partner_patient_no',
        'patient_name',
        'patient_age',
        'patient_gender',
        'registered_at',
        'reference',
        'test_code',
        'test_name',
        'status',
        'report_url',
        'ready_at',
        'last_seen_at',
        'lab_invoice_item_id',
        'attached_at',
        'attached_by',
        'ignored_at',
        'ignored_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'ready_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'attached_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    /**
     * Get the outsourced test this report was attached to.
     *
     * @return BelongsTo<LabInvoiceItem, $this>
     */
    public function labInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(LabInvoiceItem::class);
    }

    /**
     * Get the user who attached the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function attachedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by');
    }

    /**
     * Determine whether a partner portal status means the report can be printed.
     */
    public static function isReadyStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::READY_STATUSES, true);
    }

    /**
     * Determine whether this report can be printed and has a link.
     */
    public function isReady(): bool
    {
        return $this->ready_at !== null && filled($this->report_url);
    }

    /**
     * Scope the query to ready reports not attached to a test or ignored yet.
     */
    public function scopeWaiting($query)
    {
        return $query->whereNotNull('ready_at')
            ->whereNotNull('report_url')
            ->whereNull('lab_invoice_item_id')
            ->whereNull('ignored_at');
    }

    /**
     * The patient's sex as HMS stores it ("male" / "female"), when known.
     */
    public function normalizedGender(): ?string
    {
        return match (strtolower(trim((string) $this->patient_gender))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => null,
        };
    }

    /**
     * The patient's age in whole years, when the portal gives it in years.
     */
    public function ageInYears(): ?int
    {
        return preg_match('/^(\d+)\s*year/i', trim((string) $this->patient_age), $matches) ? (int) $matches[1] : null;
    }
}
