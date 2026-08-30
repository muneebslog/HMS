<?php

namespace App\Models;

use App\Enums\ReceptionMemoColor;
use App\Enums\UserRole;
use Database\Factories\ReceptionMemoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceptionMemo extends Model
{
    /** @use HasFactory<ReceptionMemoFactory> */
    use HasFactory;

    /**
     * The confirmation phrase required to mark a memo as read.
     */
    public const READ_CONFIRMATION = 'read it';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'created_by',
        'title',
        'body',
        'color',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'color' => 'amber',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => ReceptionMemoColor::class,
        ];
    }

    /**
     * The user who created this memo.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The read receipts for this memo.
     *
     * @return HasMany<ReceptionMemoRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(ReceptionMemoRead::class);
    }

    /**
     * Determine whether the given user has read this memo.
     */
    public function isReadBy(User $user): bool
    {
        return $this->reads()->where('user_id', $user->id)->exists();
    }

    /**
     * Scope a query to only include memos unread by the given user.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('reads', function (Builder $reads) use ($user) {
            $reads->where('user_id', $user->id);
        });
    }

    /**
     * Scope a query to only include memos read by the given user.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReadFor(Builder $query, User $user): Builder
    {
        return $query->whereHas('reads', function (Builder $reads) use ($user) {
            $reads->where('user_id', $user->id);
        });
    }

    /**
     * Count users who can see memos on the board.
     */
    public static function audienceCount(): int
    {
        return User::query()
            ->whereIn('role', [UserRole::Admin, UserRole::Receptionist])
            ->count();
    }

    /**
     * Determine whether the given user may delete this memo.
     */
    public function canBeDeletedBy(User $user): bool
    {
        return $user->isAdmin() || $this->created_by === $user->id;
    }
}
