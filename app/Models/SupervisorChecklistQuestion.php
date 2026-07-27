<?php

namespace App\Models;

use Database\Factories\SupervisorChecklistQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $question_text
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SupervisorChecklistQuestion extends Model
{
    /** @use HasFactory<SupervisorChecklistQuestionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
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
     * Get the answer options for this question.
     *
     * @return HasMany<SupervisorChecklistOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(SupervisorChecklistOption::class, 'question_id')->orderBy('sort_order');
    }

    /**
     * Get the responses recorded for this question.
     *
     * @return HasMany<SupervisorChecklistResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SupervisorChecklistResponse::class, 'question_id');
    }
}
