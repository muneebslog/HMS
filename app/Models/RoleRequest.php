<?php

namespace App\Models;

use App\Enums\RoleRequestStatus;
use App\Enums\UserRole;
use Database\Factories\RoleRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $processed_by
 * @property UserRole $requested_role
 * @property RoleRequestStatus $status
 * @property string|null $message
 * @property string|null $admin_notes
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 * @property User|null $processor
 */
class RoleRequest extends Model
{
    /** @use HasFactory<RoleRequestFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'requested_role',
        'status',
        'message',
        'admin_notes',
        'processed_by',
        'processed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_role' => UserRole::class,
            'status' => RoleRequestStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who submitted the request.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who processed the request.
     *
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Determine whether the request is pending.
     */
    public function isPending(): bool
    {
        return $this->status === RoleRequestStatus::Pending;
    }

    /**
     * Determine whether the request has been processed.
     */
    public function isProcessed(): bool
    {
        return ! $this->isPending();
    }
}
