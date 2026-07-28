<?php

namespace App\Models;

use Database\Factories\SupervisorChecklistOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $question_id
 * @property string $option_text
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property SupervisorChecklistQuestion $question
 */
class SupervisorChecklistOption extends Model
{
    /** @use HasFactory<SupervisorChecklistOptionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'question_id',
        'option_text',
        'is_no',
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
            'is_no' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the question this option belongs to.
     *
     * @return BelongsTo<SupervisorChecklistQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(SupervisorChecklistQuestion::class, 'question_id');
    }

    /**
     * Get the responses that selected this option.
     *
     * @return BelongsToMany<SupervisorChecklistResponse, $this>
     */
    public function responses(): BelongsToMany
    {
        return $this->belongsToMany(SupervisorChecklistResponse::class, 'supervisor_checklist_response_option', 'option_id', 'response_id');
    }
}
