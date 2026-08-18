<?php

namespace App\Models;

use Database\Factories\NurseQuestionnaireEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $questionnaire_id
 * @property int $user_id
 * @property Carbon $block_starts_at
 * @property Carbon $block_ends_at
 * @property Carbon $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property NurseQuestionnaire $questionnaire
 * @property User $user
 */
class NurseQuestionnaireEntry extends Model
{
    /** @use HasFactory<NurseQuestionnaireEntryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'questionnaire_id',
        'user_id',
        'block_starts_at',
        'block_ends_at',
        'submitted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'block_starts_at' => 'datetime',
            'block_ends_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Get the questionnaire this entry belongs to.
     *
     * @return BelongsTo<NurseQuestionnaire, $this>
     */
    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(NurseQuestionnaire::class, 'questionnaire_id');
    }

    /**
     * Get the incharge nurse who submitted this entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the individual question responses for this entry.
     *
     * @return HasMany<NurseQuestionnaireResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(NurseQuestionnaireResponse::class, 'entry_id');
    }
}
