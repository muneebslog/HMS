<?php

namespace App\Models;

use Database\Factories\NurseQuestionnaireFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $created_by
 * @property string $name
 * @property string|null $description
 * @property int $interval_hours
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User|null $creator
 */
class NurseQuestionnaire extends Model
{
    /** @use HasFactory<NurseQuestionnaireFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'interval_hours' => 2,
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'created_by',
        'name',
        'description',
        'interval_hours',
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
            'interval_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user who created this questionnaire.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the questions for this questionnaire.
     *
     * @return HasMany<NurseQuestionnaireQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(NurseQuestionnaireQuestion::class, 'questionnaire_id')->orderBy('sort_order');
    }

    /**
     * Get the submitted entries for this questionnaire.
     *
     * @return HasMany<NurseQuestionnaireEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(NurseQuestionnaireEntry::class, 'questionnaire_id');
    }

    /**
     * Scope a query to only include active questionnaires.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get a human-readable interval label.
     */
    public function intervalLabel(): string
    {
        return $this->interval_hours === 1
            ? __('Every hour')
            : __('Every :hours hours', ['hours' => $this->interval_hours]);
    }
}
