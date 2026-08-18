<?php

namespace App\Models;

use App\Enums\NurseQuestionnaireAnswer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entry_id
 * @property int $question_id
 * @property NurseQuestionnaireAnswer $answer
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property NurseQuestionnaireEntry $entry
 * @property NurseQuestionnaireQuestion $question
 */
class NurseQuestionnaireResponse extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entry_id',
        'question_id',
        'answer',
        'remarks',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answer' => NurseQuestionnaireAnswer::class,
        ];
    }

    /**
     * Get the entry this response belongs to.
     *
     * @return BelongsTo<NurseQuestionnaireEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(NurseQuestionnaireEntry::class, 'entry_id');
    }

    /**
     * Get the question this response answers.
     *
     * @return BelongsTo<NurseQuestionnaireQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(NurseQuestionnaireQuestion::class, 'question_id');
    }

    /**
     * Determine whether this response is a No answer.
     */
    public function isNo(): bool
    {
        return $this->answer === NurseQuestionnaireAnswer::No;
    }
}
