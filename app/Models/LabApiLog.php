<?php

namespace App\Models;

use App\Enums\LabApiStatus;
use Database\Factories\LabApiLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabApiLog extends Model
{
    /** @use HasFactory<LabApiLogFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lab_invoice_id',
        'status',
        'request_payload',
        'response_body',
        'http_status',
        'error_message',
        'sent_at',
        'lab_case_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LabApiStatus::class,
            'request_payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Get the lab invoice associated with this API log.
     */
    public function labInvoice(): BelongsTo
    {
        return $this->belongsTo(LabInvoice::class);
    }
}
