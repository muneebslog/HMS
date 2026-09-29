<?php

namespace App\Models;

use App\Enums\OutgoingSampleStatus;
use App\Services\CeoLabOverview;
use Carbon\CarbonImmutable;
use Database\Factories\LabInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class LabInvoiceItem extends Model
{
    /** @use HasFactory<LabInvoiceItemFactory> */
    use HasFactory;

    /**
     * The HMS columns that show a test was handled in the new workflow.
     *
     * @var list<string>
     */
    private const HMS_ACTIVITY_COLUMNS = ['sample_collected_at', 'sample_received_by', 'asked_at', 'given_at', 'report_path', 'results_completed_by'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lab_invoice_id',
        'lab_test_id',
        'test_name',
        'test_code',
        'sample',
        'time_required',
        'is_in_house',
        'sample_received_at',
        'sample_received_by',
        'sample_collected_at',
        'sample_collected_by_health_aide_id',
        'outgoing_status',
        'asked_at',
        'asked_by',
        'given_at',
        'given_by',
        'received_at',
        'received_by',
        'report_path',
        'report_original_name',
        'report_uploaded_at',
        'report_uploaded_by',
        'lab_result_ready',
        'results_completed_at',
        'results_completed_by',
        'results_imported_at',
        'result_comment',
        'price',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_in_house' => 'boolean',
            'sample_received_at' => 'datetime',
            'sample_collected_at' => 'datetime',
            'outgoing_status' => OutgoingSampleStatus::class,
            'asked_at' => 'datetime',
            'given_at' => 'datetime',
            'received_at' => 'datetime',
            'report_uploaded_at' => 'datetime',
            'lab_result_ready' => 'boolean',
            'results_completed_at' => 'datetime',
            'results_imported_at' => 'datetime',
        ];
    }

    /**
     * Delete the stored report file when the item is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (LabInvoiceItem $item): void {
            if (filled($item->report_path) && Storage::disk('local')->exists($item->report_path)) {
                Storage::disk('local')->delete($item->report_path);
            }
        });
    }

    /**
     * Get the lab invoice for this item.
     */
    public function labInvoice(): BelongsTo
    {
        return $this->belongsTo(LabInvoice::class);
    }

    /**
     * Get the lab test for this item.
     */
    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class);
    }

    /**
     * Get the user who marked the sample as asked.
     *
     * @return BelongsTo<User, $this>
     */
    public function askedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    /**
     * Get the user who marked the sample as given.
     *
     * @return BelongsTo<User, $this>
     */
    public function givenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    /**
     * Get the user who marked the sample as received.
     *
     * @return BelongsTo<User, $this>
     */
    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Get the partner lab portal report attached to this outsourced test, if any.
     *
     * @return HasOne<PartnerLabReport, $this>
     */
    public function partnerLabReport(): HasOne
    {
        return $this->hasOne(PartnerLabReport::class);
    }

    /**
     * Get the user who uploaded the outgoing report PDF.
     *
     * @return BelongsTo<User, $this>
     */
    public function reportUploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'report_uploaded_by');
    }

    /**
     * Determine whether this item is an outgoing sample.
     */
    public function isOutgoing(): bool
    {
        return ! $this->is_in_house;
    }

    /**
     * Get the results entered in the HMS for this test, one per field.
     *
     * @return HasMany<LabResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }

    /**
     * Get the user who completed this test's results in the HMS.
     *
     * @return BelongsTo<User, $this>
     */
    public function resultsCompletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'results_completed_by');
    }

    /**
     * Determine whether this test is finished, using HMS data only: an in-house
     * test once its results are completed in the HMS, a send-out test once its
     * report is received or uploaded. The old lab software's `lab_result_ready`
     * flag is deliberately not used. Tests from the old lab software count as done.
     */
    public function isDone(): bool
    {
        if ($this->isLegacy()) {
            return true;
        }

        if ($this->is_in_house) {
            return $this->results_completed_at !== null;
        }

        return $this->outgoing_status === OutgoingSampleStatus::Received || filled($this->report_path);
    }

    /**
     * The day lab work moved into the HMS, or null when there is no cutoff.
     */
    public static function trackingStartedAt(): ?CarbonImmutable
    {
        $date = config('hospital.lab.tracking_started_at');

        return filled($date) ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    /**
     * Determine whether this test was handled in the old lab software: billed before
     * lab tracking started, with nothing done to it in the HMS since.
     */
    public function isLegacy(): bool
    {
        $startedAt = self::trackingStartedAt();

        if ($startedAt === null || $this->created_at === null || $this->created_at->gte($startedAt)) {
            return false;
        }

        return collect(self::HMS_ACTIVITY_COLUMNS)->every(fn (string $column) => blank($this->getAttribute($column)));
    }

    /**
     * Scope the query to tests the HMS tracks (the inverse of isLegacy()).
     */
    public function scopeTracked($query)
    {
        $startedAt = self::trackingStartedAt();

        if ($startedAt === null) {
            return $query;
        }

        return $query->where(function ($tracked) use ($startedAt) {
            $tracked->where($this->qualifyColumn('created_at'), '>=', $startedAt);

            foreach (self::HMS_ACTIVITY_COLUMNS as $column) {
                $tracked->orWhereNotNull($this->qualifyColumn($column));
            }
        });
    }

    /**
     * Scope the query to tests billed since lab tracking started, for lab stats and charts.
     */
    public function scopeBilledSinceTracking($query)
    {
        $startedAt = self::trackingStartedAt();

        return $startedAt === null ? $query : $query->where($this->qualifyColumn('created_at'), '>=', $startedAt);
    }

    /**
     * Scope the query to tests that are not finished yet (the inverse of isDone()).
     */
    public function scopePending($query)
    {
        return $query->tracked()->where(function ($query) {
            $query
                ->where(function ($inHouse) {
                    $inHouse->where('is_in_house', true)->whereNull('results_completed_at');
                })
                ->orWhere(function ($outgoing) {
                    $outgoing->where('is_in_house', false)
                        ->whereNull('report_path')
                        ->where(fn ($status) => $status->whereNull('outgoing_status')->orWhere('outgoing_status', '!=', OutgoingSampleStatus::Received->value));
                });
        });
    }

    /**
     * When the result was promised, from the test's "time required" ("Same day", "Next day",
     * "After 3 days", or just "2"): the end of that day, and never less than two hours after billing.
     */
    public function dueAt(): CarbonImmutable
    {
        $billedAt = CarbonImmutable::parse($this->created_at);
        $timeRequired = strtolower(trim((string) $this->time_required));

        $days = match (true) {
            $timeRequired === '' || str_contains($timeRequired, 'same') => 0,
            str_contains($timeRequired, 'next') => 1,
            (bool) preg_match('/(\d+)/', $timeRequired, $matches) => (int) $matches[1],
            default => 1,
        };

        return $billedAt->addDays($days)->endOfDay()->max($billedAt->addHours(2));
    }

    /**
     * Get the lab user who received this test's sample.
     *
     * @return BelongsTo<User, $this>
     */
    public function sampleReceivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sample_received_by');
    }

    /**
     * Get the health aide who collected this test's sample at the ER Station
     * (the lab still has to receive it).
     *
     * @return BelongsTo<HealthAide, $this>
     */
    public function sampleCollectedByHealthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class, 'sample_collected_by_health_aide_id');
    }

    /**
     * Scope the query to samples still to be collected at the ER Station: awaited by the lab
     * and not yet collected.
     */
    public function scopeAwaitingCollection($query)
    {
        return $query->awaitingSample()->whereNull('sample_collected_at');
    }

    /**
     * Get every retake the lab asked for on this test.
     *
     * @return HasMany<LabSampleRetake, $this>
     */
    public function retakes(): HasMany
    {
        return $this->hasMany(LabSampleRetake::class);
    }

    /**
     * Get the latest retake the lab asked for on this test, if any.
     *
     * @return HasOne<LabSampleRetake, $this>
     */
    public function latestRetake(): HasOne
    {
        return $this->hasOne(LabSampleRetake::class)->latestOfMany();
    }

    /**
     * Determine whether a retake is waiting for the patient to come back.
     */
    public function hasOpenRetake(): bool
    {
        return $this->latestRetake?->isOpen() ?? false;
    }

    /**
     * Scope the query to in-house tests whose sample the lab should be receiving now:
     * not received, not finished, no retake waiting on the patient, case not returned.
     */
    public function scopeAwaitingSample($query)
    {
        return $query
            ->tracked()
            ->where('is_in_house', true)
            ->whereNull('sample_received_at')
            ->whereNull('results_completed_at')
            ->whereDoesntHave('retakes', fn ($retakes) => $retakes->open())
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'));
    }

    /**
     * Scope the query to send-out tests that still need the rider: not called yet, or called but not handed over.
     */
    public function scopeAwaitingRider($query)
    {
        return $query
            ->tracked()
            ->where('is_in_house', false)
            ->whereIn('outgoing_status', [OutgoingSampleStatus::Pending->value, OutgoingSampleStatus::Asked->value])
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'));
    }

    /**
     * Scope the query to send-out tests handed to the rider more than the allowed days ago
     * whose result has not come back, on cases that were not returned.
     */
    public function scopeLateAtPartnerLab($query)
    {
        $lateBefore = now()->subDays(CeoLabOverview::PARTNER_LAB_DAYS);

        return $query
            ->pending()
            ->where('is_in_house', false)
            ->where('outgoing_status', OutgoingSampleStatus::Given->value)
            ->where(fn ($late) => $late
                ->where('given_at', '<', $lateBefore)
                ->orWhere(fn ($noTime) => $noTime->whereNull('given_at')->where('created_at', '<', $lateBefore)))
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'));
    }

    /**
     * Mark a send-out test's result as back from the partner lab, if it is not already.
     */
    public function markOutgoingReceived(?int $userId): void
    {
        if ($this->is_in_house || $this->outgoing_status === OutgoingSampleStatus::Received) {
            return;
        }

        $this->update([
            'outgoing_status' => OutgoingSampleStatus::Received,
            'received_at' => now(),
            'received_by' => $userId,
        ]);
    }

    /**
     * Put a send-out test back with the partner lab once nothing it came back with is kept:
     * no uploaded report and no completed results.
     */
    public function reopenOutgoing(): void
    {
        if ($this->is_in_house
            || $this->outgoing_status !== OutgoingSampleStatus::Received
            || filled($this->report_path)
            || $this->results_completed_at !== null) {
            return;
        }

        $this->update([
            'outgoing_status' => OutgoingSampleStatus::Given,
            'received_at' => null,
            'received_by' => null,
        ]);
    }

    /**
     * Determine whether a report PDF is stored for this item.
     */
    public function hasReport(): bool
    {
        return filled($this->report_path) && Storage::disk('local')->exists($this->report_path);
    }
}
