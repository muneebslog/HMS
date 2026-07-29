<?php

namespace App\Models;

use Database\Factories\AdminReportMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminReportMessage extends Model
{
    /** @use HasFactory<AdminReportMessageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin_report_id',
        'user_id',
        'body',
    ];

    /**
     * The report this message belongs to.
     *
     * @return BelongsTo<AdminReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(AdminReport::class, 'admin_report_id');
    }

    /**
     * The user who wrote this message.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
