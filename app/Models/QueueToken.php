<?php

namespace App\Models;

use Database\Factories\QueueTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueToken extends Model
{
    /** @use HasFactory<QueueTokenFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_queue_id',
        'invoice_item_id',
        'token_number',
        'status',
    ];

    /**
     * Get the queue this token belongs to.
     *
     * @return BelongsTo<ServiceQueue, $this>
     */
    public function serviceQueue(): BelongsTo
    {
        return $this->belongsTo(ServiceQueue::class);
    }

    /**
     * Get the invoice item this token was issued for.
     *
     * @return BelongsTo<InvoiceItem, $this>
     */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}
