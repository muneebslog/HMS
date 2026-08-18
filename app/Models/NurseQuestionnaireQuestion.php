<?php

namespace App\Models;

use Database\Factories\NurseQuestionnaireQuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $questionnaire_id
 * @property string $question_text
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property NurseQuestionnaire $questionnaire
 */
class NurseQuestionnaireQuestion extends Model
{
    /** @use HasFactory<NurseQuestionnaireQuestionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'questionnaire_id',
        'question_text',
        'sort_order',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the questionnaire this question belongs to.
     *
     * @return BelongsTo<NurseQuestionnaire, $this>
     */
    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(NurseQuestionnaire::class, 'questionnaire_id');
    }

    /**
     * Get the responses recorded for this question.
     *
     * @return HasMany<NurseQuestionnaireResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(NurseQuestionnaireResponse::class, 'question_id');
    }

    /**
     * Scope a query to only include active questions.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
