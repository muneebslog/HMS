<?php

namespace App\Models;

use App\Enums\AdminReportStatus;
use Database\Factories\AdminReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminReport extends Model
{
    /** @use HasFactory<AdminReportFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'created_by',
        'subject',
        'status',
        'last_message_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AdminReportStatus::class,
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The user who created this report.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The messages on this report.
     *
     * @return HasMany<AdminReportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AdminReportMessage::class)->orderBy('created_at');
    }

    /**
     * Scope a query to only include open reports.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AdminReportStatus::Open);
    }
}
