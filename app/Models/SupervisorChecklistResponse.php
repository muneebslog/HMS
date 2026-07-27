<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entry_id
 * @property int $question_id
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property SupervisorChecklistEntry $entry
 * @property SupervisorChecklistQuestion $question
 */
class SupervisorChecklistResponse extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entry_id',
        'question_id',
        'remarks',
    ];

    /**
     * Get the entry this response belongs to.
     *
     * @return BelongsTo<SupervisorChecklistEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(SupervisorChecklistEntry::class, 'entry_id');
    }

    /**
     * Get the question this response answers.
     *
     * @return BelongsTo<SupervisorChecklistQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(SupervisorChecklistQuestion::class, 'question_id');
    }

    /**
     * Get the selected options for this response.
     *
     * @return BelongsToMany<SupervisorChecklistOption, $this>
     */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(SupervisorChecklistOption::class, 'supervisor_checklist_response_option', 'response_id', 'option_id');
    }
}
