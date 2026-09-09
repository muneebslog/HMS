<?php

namespace App\Models;

use Database\Factories\ChatConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ChatConversation extends Model
{
    /** @use HasFactory<ChatConversationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_one_id',
        'user_two_id',
        'last_message_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The lower-id participant.
     *
     * @return BelongsTo<User, $this>
     */
    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    /**
     * The higher-id participant.
     *
     * @return BelongsTo<User, $this>
     */
    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    /**
     * Messages in this conversation.
     *
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    /**
     * Scope conversations that include the given user.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $builder) use ($user): void {
            $builder->where('user_one_id', $user->id)
                ->orWhere('user_two_id', $user->id);
        });
    }

    /**
     * Determine whether the user is a participant.
     */
    public function hasParticipant(User $user): bool
    {
        return $this->user_one_id === $user->id || $this->user_two_id === $user->id;
    }

    /**
     * Get the other participant relative to the given user.
     */
    public function otherParticipant(User $user): User
    {
        if ($this->user_one_id === $user->id) {
            return $this->relationLoaded('userTwo')
                ? $this->userTwo
                : $this->userTwo()->firstOrFail();
        }

        return $this->relationLoaded('userOne')
            ? $this->userOne
            : $this->userOne()->firstOrFail();
    }

    /**
     * Find or create a direct conversation between two users.
     */
    public static function findOrCreateBetween(User $first, User $second): self
    {
        if ($first->id === $second->id) {
            throw new \InvalidArgumentException('Cannot create a conversation with yourself.');
        }

        [$userOneId, $userTwoId] = $first->id < $second->id
            ? [$first->id, $second->id]
            : [$second->id, $first->id];

        return DB::transaction(function () use ($userOneId, $userTwoId): self {
            $conversation = self::query()
                ->where('user_one_id', $userOneId)
                ->where('user_two_id', $userTwoId)
                ->lockForUpdate()
                ->first();

            if ($conversation !== null) {
                return $conversation;
            }

            return self::query()->create([
                'user_one_id' => $userOneId,
                'user_two_id' => $userTwoId,
            ]);
        });
    }
}
